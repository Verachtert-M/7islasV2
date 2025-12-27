<?php

namespace App\Services;

use App\Events\EventDispatcher;
use App\Services\Logger;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Email service using PHPMailer for sending form submissions
 */
class EmailService
{
    private array $config;
    private EventDispatcher $events;
    private ?string $lastError = null;

    public function __construct(array $config = [], ?EventDispatcher $events = null)
    {
        $this->config = array_merge([
            // SMTP Configuration (SMTP only)
            'from_email' => 'noreply@example.com',
            'from_name' => 'Website Form',
            'reply_to' => null,
            'smtp_host' => 'localhost',
            'smtp_port' => 587,
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_encryption' => 'tls', // tls, ssl, none
            'smtp_auth' => true,
            'smtp_debug' => false,
            'smtp_timeout' => 30,
            // Email Options
            'charset' => 'UTF-8',
            'word_wrap' => 76,
            'priority' => 3, // 1 = High, 3 = Normal, 5 = Low
            'confirm_reading_to' => null,
            // Security
            'allow_empty_to' => false,
            'validate_addresses' => true,
        ], $config);
        $this->events = $events ?? new EventDispatcher();
    }

    /**
     * Send form submission email
     */
    public function sendFormSubmission(array $formData, array $options = [], array $configOverride = [], $logger = null): bool
    {
        // Merge config for this send only (do not mutate $this->config)
        $config = array_merge($this->config, $configOverride);
        // Use provided logger or fallback to global
        $logger = $logger ?: Logger::getInstance();

        try {
            $to = $options['to'] ?? $config['to'] ?? null;
            $subject = $options['subject'] ?? 'New Form Submission';
            $template = $options['template'] ?? 'default';

            if (!$to) {
                $logger->error('No recipient email address provided', [
                    'options' => $options,
                    'formData' => $formData
                ]);
                throw new \InvalidArgumentException('No recipient email address provided');
            }

            // Dispatch email sending event
            $this->events->dispatch('email.sending', [
                'to' => $to,
                'subject' => $subject,
                'data' => $formData,
                'template' => $template
            ]);

            $mail = $this->createMailerWithConfig($config);

            // Configure recipients
            $this->configureRecipientsWithConfig($mail, $to, $options, $config);

            // Configure sender
            $this->configureSenderWithConfig($mail, $options, $config, $formData);

            // Configure content
            $this->configureContent($mail, $subject, $formData, $template, $options, $logger);

            // Debug: Log PHPMailer state before send
            $logger->debug('EmailService: PHPMailer ready to send', [
                'recipients' => $mail->getToAddresses(),
                'subject' => $mail->Subject,
                'from' => $mail->From,
                'attachments_count' => count($options['attachments'] ?? [])
            ]);

            // Attempt to send
            $success = $mail->send();
            $this->lastError = null;

            if (!$success) {
                $this->lastError = $mail->ErrorInfo;
                $logger->error('EmailService: PHPMailer send failed', [
                    'error' => $mail->ErrorInfo,
                    'to' => $to,
                    'subject' => $subject
                ]);
            } else {
                $logger->debug('EmailService: PHPMailer send success', [
                    'to' => $to,
                    'subject' => $subject,
                    'message_id' => $mail->getLastMessageID()
                ]);
            }

            // Dispatch completion event
            $this->events->dispatch($success ? 'email.sent' : 'email.failed', [
                'to' => $to,
                'subject' => $subject,
                'data' => $formData,
                'success' => $success,
                'error' => $success ? null : $mail->ErrorInfo
            ]);

            return $success;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();

            // Obfuscate SMTP password before logging config
            if (isset($config['smtp_password'])) {
                $config['smtp_password'] = '********';
            } else {
                $config['smtp_password'] = null;
            }

            $logger->error('EmailService: Exception in sendFormSubmission', [
                'error' => $e->getMessage(),
                'exception' => $e,
                'formData' => $formData,
                'options' => $options,
                'config' => $config
            ]);

            $this->events->dispatch('email.error', [
                'error' => $e->getMessage(),
                'data' => $formData ?? [],
                'options' => $options
            ]);

            return false;
        }
    }

    /**
     * Send notification email
     */
    public function sendNotification(string $to, string $subject, string $message, array $options = []): bool
    {
        try {
            $mail = $this->createMailer();

            // Configure recipients
            $this->configureRecipients($mail, $to, $options);

            // Configure sender
            $this->configureSender($mail, $options);

            // Set subject and body
            $mail->Subject = $subject;

            if ($options['html'] ?? true) {
                $mail->isHTML(true);
                $mail->Body = $message;
                // Generate plain text version if not provided
                if (empty($options['alt_body'])) {
                    $mail->AltBody = strip_tags($message);
                } else {
                    $mail->AltBody = $options['alt_body'];
                }
            } else {
                $mail->isHTML(false);
                $mail->Body = $message;
            }

            return $mail->send();
        } catch (Exception $e) {
            Logger::getInstance()->error("PHPMailer Notification Error: " . $e->getMessage(), [
                'exception' => $e,
                'to' => $to,
                'subject' => $subject,
                'options' => $options
            ]);
            return false;
        }
    }

    /**
     * Send bulk emails
     */
    public function sendBulk(array $recipients, string $subject, string $message, array $options = []): array
    {
        $results = [];
        $mail = $this->createMailer();

        // Configure sender once
        $this->configureSender($mail, $options);
        $mail->Subject = $subject;

        if ($options['html'] ?? true) {
            $mail->isHTML(true);
            $mail->Body = $message;
            $mail->AltBody = $options['alt_body'] ?? strip_tags($message);
        } else {
            $mail->isHTML(false);
            $mail->Body = $message;
        }

        foreach ($recipients as $recipient) {
            try {
                $mail->clearAddresses();
                $mail->addAddress($recipient);

                $success = $mail->send();
                $results[$recipient] = [
                    'success' => $success,
                    'error' => $success ? null : $mail->ErrorInfo,
                    'message_id' => $success ? $mail->getLastMessageID() : null
                ];
            } catch (Exception $e) {
                $results[$recipient] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'message_id' => null
                ];
            }
        }

        return $results;
    }

    /**
     * Create and configure PHPMailer instance
     */
    private function createMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);
        $this->configureSMTP($mail);
        // General configuration
        $mail->CharSet = $this->config['charset'];
        $mail->WordWrap = $this->config['word_wrap'];
        $mail->Priority = $this->config['priority'];
        if ($this->config['confirm_reading_to']) {
            $mail->ConfirmReadingTo = $this->config['confirm_reading_to'];
        }
        return $mail;
    }

    /**
     * Configure SMTP settings
     */
    private function configureSMTP(PHPMailer $mail): void
    {
        $mail->isSMTP();
        $mail->Host = $this->config['smtp_host'];
        $mail->Port = $this->config['smtp_port'];
        $mail->Timeout = $this->config['smtp_timeout'];

        if ($this->config['smtp_auth']) {
            $mail->SMTPAuth = true;
            $mail->Username = $this->config['smtp_username'];
            $mail->Password = $this->config['smtp_password'];
        }

        // Configure encryption
        switch ($this->config['smtp_encryption']) {
            case 'tls':
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                break;
            case 'ssl':
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                break;
            default:
                $mail->SMTPSecure = '';
                break;
        }

        // Debug mode
        if ($this->config['smtp_debug']) {
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            $mail->Debugoutput = function ($str, $level) {
                Logger::getInstance()->debug("SMTP Debug ($level): " . trim($str));
            };
        }

        // Additional SMTP options
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => $this->config['smtp_verify_peer'] ?? false,
                'verify_peer_name' => $this->config['smtp_verify_peer_name'] ?? false,
                'allow_self_signed' => $this->config['smtp_allow_self_signed'] ?? true
            ]
        ];
    }

    /**
     * Configure email recipients
     */
    private function configureRecipients(PHPMailer $mail, string|array $to, array $options): void
    {
        // Clear any existing addresses
        $mail->clearAddresses();
        $mail->clearCCs();
        $mail->clearBCCs();

        // Add primary recipients
        if (is_string($to)) {
            $to = [$to];
        }

        foreach ($to as $recipient) {
            if ($this->config['validate_addresses'] && !$this->validateEmail($recipient)) {
                throw new \InvalidArgumentException("Invalid email address: $recipient");
            }
            $mail->addAddress($recipient);
        }

        // Add CC recipients
        if (!empty($options['cc'])) {
            $ccList = is_string($options['cc']) ? [$options['cc']] : $options['cc'];
            foreach ($ccList as $cc) {
                if ($this->config['validate_addresses'] && !$this->validateEmail($cc)) {
                    throw new \InvalidArgumentException("Invalid CC email address: $cc");
                }
                $mail->addCC($cc);
            }
        }

        // Add BCC recipients
        if (!empty($options['bcc'])) {
            $bccList = is_string($options['bcc']) ? [$options['bcc']] : $options['bcc'];
            foreach ($bccList as $bcc) {
                if ($this->config['validate_addresses'] && !$this->validateEmail($bcc)) {
                    throw new \InvalidArgumentException("Invalid BCC email address: $bcc");
                }
                $mail->addBCC($bcc);
            }
        }
    }

    /**
     * Configure email sender
     */
    private function configureSender(PHPMailer $mail, array $options, array $formData = []): void
    {
        $fromEmail = $options['from_email'] ?? $this->config['from_email'];
        $fromName = $options['from_name'] ?? $this->config['from_name'];

        $mail->setFrom($fromEmail, $fromName);

        // Set reply-to address
        $replyTo = $options['reply_to'] ?? $formData['email'] ?? $this->config['reply_to'];
        if ($replyTo && $this->validateEmail($replyTo)) {
            $replyToName = $formData['name'] ?? $options['reply_to_name'] ?? '';
            $mail->addReplyTo($replyTo, $replyToName);
        }

        // Set return path if specified
        if (!empty($options['return_path'])) {
            $mail->Sender = $options['return_path'];
        }
    }

    /**
     * Configure email content, attachments, and templates
     */
    private function configureContent(PHPMailer $mail, string $subject, array $formData, string $template, array $options, $logger = null): void
    {
        // Use provided logger or fallback to global
        $logger = $logger ?: Logger::getInstance();

        // Set subject
        $mail->Subject = $subject;

        // Set HTML mode
        $mail->isHTML(true);

        // Build email body
        $mail->Body = $this->buildEmailBody($formData, $template, $options);

        // Generate plain text alternative
        $mail->AltBody = $options['alt_body'] ?? $this->buildPlainTextBody($formData);

        // Add attachments if specified
        if (!empty($options['attachments'])) {
            foreach ($options['attachments'] as $attachment) {
                if (is_string($attachment)) {
                    $mail->addAttachment($attachment);
                } elseif (is_array($attachment)) {
                    $mail->addAttachment(
                        $attachment['path'],
                        $attachment['name'] ?? '',
                        $attachment['encoding'] ?? 'base64',
                        $attachment['type'] ?? ''
                    );
                } elseif ($attachment instanceof UploadedFileInterface) {
                    // Handle PSR-7 uploaded files
                    if ($attachment->getError() === UPLOAD_ERR_OK && $attachment->getSize() > 0) {
                        // Create temporary file
                        $tmpPath = tempnam(sys_get_temp_dir(), 'email_attachment_');
                        $stream = $attachment->getStream();
                        $stream->rewind();

                        // Write uploaded file to temp file
                        file_put_contents($tmpPath, $stream->getContents());

                        // Add to email
                        $mail->addAttachment(
                            $tmpPath,
                            $attachment->getClientFilename(),
                            'base64',
                            $attachment->getClientMediaType()
                        );

                        // Register temp file for cleanup
                        register_shutdown_function(function () use ($tmpPath) {
                            if (file_exists($tmpPath)) {
                                unlink($tmpPath);
                            }
                        });

                        $logger->debug('Added email attachment', [
                            'filename' => $attachment->getClientFilename(),
                            'size' => $attachment->getSize(),
                            'type' => $attachment->getClientMediaType(),
                            'tmp_path' => $tmpPath
                        ]);
                    } else {
                        $logger->warning('Skipping invalid uploaded file attachment', [
                            'error' => $attachment->getError(),
                            'size' => $attachment->getSize(),
                            'filename' => $attachment->getClientFilename()
                        ]);
                    }
                }
            }
        }

        // Add embedded images if specified
        if (!empty($options['embedded_images'])) {
            foreach ($options['embedded_images'] as $cid => $imagePath) {
                $mail->addEmbeddedImage($imagePath, $cid);
            }
        }
    }

    /**
     * Build email body from form data
     */
    private function buildEmailBody(array $formData, string $template, array $options = []): string
    {
        $customTemplate = $options['custom_template'] ?? null;

        if ($customTemplate && is_callable($customTemplate)) {
            return $customTemplate($formData, $options);
        }

        switch ($template) {
            case 'table':
                return $this->buildTableTemplate($formData, $options);
            case 'simple':
                return $this->buildSimpleTemplate($formData, $options);
            case 'modern':
                return $this->buildModernTemplate($formData, $options);
            default:
                return $this->buildDefaultTemplate($formData, $options);
        }
    }

    /**
     * Build default email template with improved styling
     */
    private function buildDefaultTemplate(array $formData, array $options = []): string
    {
        $title = $options['email_title'] ?? 'New Form Submission';
        $footer = $options['email_footer'] ?? '';
        $primaryColor = $options['primary_color'] ?? '#007cba';
        $backgroundColor = $options['background_color'] ?? '#f8f9fa';

        $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($title) . '</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 20px; background-color: ' . $backgroundColor . '; }
        .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden; }
        .header { background: ' . $primaryColor . '; color: white; padding: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 30px; }
        .field { margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 5px; border-left: 4px solid ' . $primaryColor . '; }
        .field-label { font-weight: bold; color: #555; margin-bottom: 5px; text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px; }
        .field-value { color: #333; word-wrap: break-word; }
        .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>' . htmlspecialchars($title) . '</h1>
        </div>
        <div class="content">';

        foreach ($formData as $key => $value) {
            if (str_starts_with($key, '_')) {
                continue; // Skip metadata fields
            }

            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $label = $this->formatFieldLabel($key);
            $html .= '<div class="field">
                <div class="field-label">' . htmlspecialchars($label) . '</div>
                <div class="field-value">' . nl2br(htmlspecialchars($value)) . '</div>
            </div>';
        }

        // Add attachment info if attachments are present
        if (!empty($options['attachments']) && count($options['attachments']) > 0) {
            $html .= '<div class="field">
                <div class="field-label">Attachments</div>
                <div class="field-value">' . count($options['attachments']) . ' file(s) attached to this email</div>
            </div>';
        }

        $html .= '</div>';

        // Add footer if provided
        if (!empty($footer)) {
            $html .= '<div class="footer">' . nl2br(htmlspecialchars($footer)) . '</div>';
        }

        $html .= '
    </div>
</body>
</html>';

        return $html;
    }

    /**
     * Build table email template
     */
    private function buildTableTemplate(array $formData, array $options = []): string
    {
        $title = $options['email_title'] ?? 'New Form Submission';
        $footer = $options['email_footer'] ?? '';

        $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>' . htmlspecialchars($title) . '</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 20px; }
        h2 { color: #007cba; border-bottom: 2px solid #007cba; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border: 1px solid #ddd; }
        th { background-color: #f8f9fa; font-weight: bold; color: #555; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; text-align: center; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <h2>' . htmlspecialchars($title) . '</h2>
    
    <table>
        <thead>
            <tr>
                <th>Field</th>
                <th>Value</th>
            </tr>
        </thead>
        <tbody>';

        foreach ($formData as $key => $value) {
            if (str_starts_with($key, '_')) {
                continue;
            }

            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $label = $this->formatFieldLabel($key);
            $html .= '<tr>
                <td><strong>' . htmlspecialchars($label) . '</strong></td>
                <td>' . nl2br(htmlspecialchars($value)) . '</td>
            </tr>';
        }

        $html .= '</tbody>
    </table>';

        // Add footer if provided
        if (!empty($footer)) {
            $html .= '<div class="footer">' . nl2br(htmlspecialchars($footer)) . '</div>';
        }

        $html .= '
</body>
</html>';

        return $html;
    }

    /**
     * Build simple email template
     */
    private function buildSimpleTemplate(array $formData, array $options = []): string
    {
        $title = $options['email_title'] ?? 'New Form Submission';
        $footer = $options['email_footer'] ?? '';

        $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($title) . '</title>
    <style>
        @import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap");
        
        body { 
            font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; 
            line-height: 1.6; 
            color: #333333; 
            margin: 0; 
            padding: 20px; 
            background: #ffffff;
            font-size: 15px;
            -webkit-font-smoothing: antialiased;
        }
        .container { 
            max-width: 600px; 
            margin: 0 auto; 
        }
        h1 { 
            font-size: 20px; 
            font-weight: 600; 
            color: #1a1a1a; 
            margin: 0 0 24px 0;
        }
        p { 
            margin: 0 0 16px 0; 
            line-height: 1.6;
        }
        .field-label { 
            font-weight: 600; 
            color: #1a1a1a; 
        }
        .field-value { 
            color: #333333; 
        }
        .footer { 
            margin-top: 32px; 
            padding-top: 16px; 
            border-top: 1px solid #e5e5e5; 
            color: #666666; 
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>' . htmlspecialchars($title) . '</h1>';

        foreach ($formData as $key => $value) {
            if (str_starts_with($key, '_')) {
                continue;
            }

            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $label = $this->formatFieldLabel($key);
            $html .= '<p><span class="field-label">' . htmlspecialchars($label) . ':</span> <span class="field-value">' . nl2br(htmlspecialchars($value)) . '</span></p>';
        }

        // Add footer if provided
        if (!empty($footer)) {
            $html .= '<div class="footer">' . nl2br(htmlspecialchars($footer)) . '</div>';
        }

        $html .= '
    </div>
</body>
</html>';

        return $html;
    }

    /**
     * Build modern email template
     */
    private function buildModernTemplate(array $formData, array $options = []): string
    {
        $title = $options['email_title'] ?? 'New Form Submission';
        $footer = $options['email_footer'] ?? '';

        $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($title) . '</title>
    <style>
        @import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap");
        
        body { 
            font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; 
            line-height: 1.7; 
            color: #1a1a1a; 
            margin: 0; 
            padding: 40px 20px; 
            background: #ffffff;
            font-size: 15px;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        .container { 
            max-width: 600px; 
            margin: 0 auto; 
            background: #ffffff; 
        }
        .header { 
            padding: 0 0 32px 0; 
            border-bottom: 1px solid #e5e5e5;
            margin-bottom: 40px;
        }
        .header h1 { 
            margin: 0; 
            font-size: 28px; 
            font-weight: 600; 
            color: #0a0a0a;
            letter-spacing: -0.02em;
        }
        .content { 
            padding: 0; 
        }
        .field { 
            margin-bottom: 32px; 
        }
        .field-label { 
            font-weight: 600; 
            color: #0a0a0a; 
            margin-bottom: 10px; 
            font-size: 13px; 
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .field-value { 
            color: #666666; 
            font-size: 15px; 
            line-height: 1.6;
            word-wrap: break-word;
            font-weight: 400;
        }
        .footer { 
            margin-top: 48px;
            padding-top: 32px; 
            border-top: 1px solid #e5e5e5;
            text-align: center; 
            font-size: 13px; 
            color: #999999;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>' . htmlspecialchars($title) . '</h1>
        </div>
        <div class="content">';

        foreach ($formData as $key => $value) {
            if (str_starts_with($key, '_')) {
                continue;
            }

            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $label = $this->formatFieldLabel($key);
            $html .= '<div class="field">
                <div class="field-label">' . htmlspecialchars($label) . '</div>
                <div class="field-value">' . nl2br(htmlspecialchars($value)) . '</div>
            </div>';
        }

        $html .= '</div>';

        // Add footer if provided
        if (!empty($footer)) {
            $html .= '<div class="footer">' . nl2br(htmlspecialchars($footer)) . '</div>';
        }

        $html .= '
    </div>
</body>
</html>';

        return $html;
    }

    /**
     * Build plain text body for alt content
     */
    private function buildPlainTextBody(array $formData): string
    {
        $text = "New Form Submission\n";

        foreach ($formData as $key => $value) {
            if (str_starts_with($key, '_')) {
                continue;
            }

            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $label = $this->formatFieldLabel($key);
            $text .= $label . ": " . $value . "\n";
        }

        return $text;
    }

    /**
     * Format field label for display
     */
    private function formatFieldLabel(string $key): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $key));
    }

    /**
     * Darken a hex color by a percentage
     */
    private function darkenColor(string $hex, int $percent): string
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        $r = max(0, min(255, $r - ($r * $percent / 100)));
        $g = max(0, min(255, $g - ($g * $percent / 100)));
        $b = max(0, min(255, $b - ($b * $percent / 100)));

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * Test email configuration
     */
    public function testConnection(): array
    {
        try {
            $mail = $this->createMailer();
            $mail->smtpConnect();
            $mail->smtpClose();
            return [
                'success' => true,
                'message' => 'SMTP connection successful',
                'host' => $this->config['smtp_host'],
                'port' => $this->config['smtp_port'],
                'encryption' => $this->config['smtp_encryption']
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
                'error_code' => $e->getCode()
            ];
        }
    }

    /**
     * Validate email address
     */
    public function validateEmail(string $email): bool
    {
        return PHPMailer::validateAddress($email);
    }

    /**
     * Get email configuration
     */
    public function getConfig(): array
    {
        // Return config without sensitive information
        $config = $this->config;
        unset($config['smtp_password']);
        return $config;
    }

    /**
     * Get PHPMailer version info
     */
    public function getVersion(): string
    {
        return PHPMailer::VERSION;
    }

    /**
     * Get last error message
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    // New: createMailerWithConfig
    private function createMailerWithConfig(array $config): PHPMailer
    {
        $mail = new PHPMailer(true);
        $this->configureSMTPWithConfig($mail, $config);
        // General configuration
        $mail->CharSet = $config['charset'];
        $mail->WordWrap = $config['word_wrap'];
        $mail->Priority = $config['priority'];
        if (!empty($config['confirm_reading_to'])) {
            $mail->ConfirmReadingTo = $config['confirm_reading_to'];
        }
        return $mail;
    }

    // New: configureSMTPWithConfig
    private function configureSMTPWithConfig(PHPMailer $mail, array $config): void
    {
        $mail->isSMTP();
        $mail->Host = $config['smtp_host'];
        $mail->Port = $config['smtp_port'];
        $mail->Timeout = $config['smtp_timeout'];

        if ($config['smtp_auth']) {
            $mail->SMTPAuth = true;
            $mail->Username = $config['smtp_username'];
            $mail->Password = $config['smtp_password'];
        }

        // Configure encryption
        switch ($config['smtp_encryption']) {
            case 'tls':
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                break;
            case 'ssl':
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                break;
            default:
                $mail->SMTPSecure = '';
                break;
        }

        // Debug mode
        if (!empty($config['smtp_debug'])) {
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            $mail->Debugoutput = function ($str, $level) {
                Logger::getInstance()->debug("SMTP Debug ($level): " . trim($str));
            };
        }

        // Additional SMTP options
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => $config['smtp_verify_peer'] ?? false,
                'verify_peer_name' => $config['smtp_verify_peer_name'] ?? false,
                'allow_self_signed' => $config['smtp_allow_self_signed'] ?? true
            ]
        ];
    }

    // New: configureRecipientsWithConfig
    private function configureRecipientsWithConfig(PHPMailer $mail, string|array $to, array $options, array $config): void
    {
        $mail->clearAddresses();
        $mail->clearCCs();
        $mail->clearBCCs();

        if (is_string($to)) {
            $to = [$to];
        }

        foreach ($to as $recipient) {
            if ($config['validate_addresses'] && !$this->validateEmail($recipient)) {
                throw new \InvalidArgumentException("Invalid email address: $recipient");
            }
            $mail->addAddress($recipient);
        }

        if (!empty($options['cc'])) {
            $ccList = is_string($options['cc']) ? [$options['cc']] : $options['cc'];
            foreach ($ccList as $cc) {
                if ($config['validate_addresses'] && !$this->validateEmail($cc)) {
                    throw new \InvalidArgumentException("Invalid CC email address: $cc");
                }
                $mail->addCC($cc);
            }
        }

        if (!empty($options['bcc'])) {
            $bccList = is_string($options['bcc']) ? [$options['bcc']] : $options['bcc'];
            foreach ($bccList as $bcc) {
                if ($config['validate_addresses'] && !$this->validateEmail($bcc)) {
                    throw new \InvalidArgumentException("Invalid BCC email address: $bcc");
                }
                $mail->addBCC($bcc);
            }
        }
    }

    // New: configureSenderWithConfig
    private function configureSenderWithConfig(PHPMailer $mail, array $options, array $config, array $formData = []): void
    {
        $fromEmail = $options['from_email'] ?? $config['from_email'];
        $fromName = $options['from_name'] ?? $config['from_name'];

        $mail->setFrom($fromEmail, $fromName);

        $replyTo = $options['reply_to'] ?? $formData['email'] ?? $config['reply_to'];
        if ($replyTo && $this->validateEmail($replyTo)) {
            $replyToName = $formData['name'] ?? $options['reply_to_name'] ?? '';
            $mail->addReplyTo($replyTo, $replyToName);
        }

        if (!empty($options['return_path'])) {
            $mail->Sender = $options['return_path'];
        }
    }
}
