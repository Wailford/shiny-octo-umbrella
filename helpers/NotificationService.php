<?php
/**
 * NotificationService
 *
 * Sends student report card notifications to parents via:
 *   - SMS     (zenoph.notify SDK  →  smsonlinegh.com)
 *   - WhatsApp (smsonlinegh direct REST API)
 *   - Email   (PHP mail())
 */

// Load the zenoph autoloader once
require_once __DIR__ . '/../zenoph.notify-2.25.08-php/lib/Zenoph/Notify/AutoLoader.php';

use Zenoph\Notify\Enums\AuthModel;
use Zenoph\Notify\Enums\SMSType;
use Zenoph\Notify\Request\SMSRequest;

class NotificationService
{
    private string $apiKey;
    private string $senderId;
    private string $whatsappSender;
    private string $emailFrom;
    private string $emailFromName;
    private string $host = 'api.smsonlinegh.com';

    /**
     * @param array $settings  Keys: zenoph_api_key, zenoph_sender_id,
     *                         zenoph_whatsapp_sender, notification_email_from,
     *                         notification_email_name
     */
    public function __construct(array $settings)
    {
        $this->apiKey         = trim($settings['zenoph_api_key']          ?? '');
        $this->senderId       = trim($settings['zenoph_sender_id']         ?? 'SCHOOL');
        $this->whatsappSender = trim($settings['zenoph_whatsapp_sender']   ?? '');
        $this->emailFrom      = trim($settings['notification_email_from']  ?? '');
        $this->emailFromName  = trim($settings['notification_email_name']  ?? 'School');
    }

    // ------------------------------------------------------------------ SMS --
    /**
     * Send an SMS message via the zenoph SDK.
     *
     * @param string $phone   E.164 or local format (e.g. 0244123456 / 233244123456)
     * @param string $message Plain-text message body
     * @return array{success:bool, error?:string}
     */
    public function sendSMS(string $phone, string $message): array
    {
        if ($this->apiKey === '') {
            return ['success' => false, 'error' => 'SMS API key not configured. Set it in Settings → Notification Settings.'];
        }

        try {
            $request = new SMSRequest();
            $request->setHost($this->host);
            // Use local CA bundle so HTTPS works on localhost/XAMPP
            $request->useSecureConnection(true, true);
            $request->setAuthModel(AuthModel::API_KEY);
            $request->setAuthApiKey($this->apiKey);
            $request->setSender($this->senderId);
            $request->setMessage($message);
            $request->setSMSType(SMSType::GSM_DEFAULT);
            $request->adddestination($this->normalisePhone($phone));
            $resp = $request->submit();

            $handshake = $resp->getRequestHandShake();
            if ($handshake === 0) { // RequestHandshake::HSHK_OK
                $report = $resp->getReport();
                $batch  = $report ? $report->getBatchId() : null;
                return ['success' => true, 'batch_id' => $batch];
            }
            return ['success' => false, 'error' => "API handshake error code: $handshake"];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // --------------------------------------------------------------- WhatsApp --
    /**
     * Send a WhatsApp message via the smsonlinegh REST API.
     *
     * @param string $phone   Phone number of recipient
     * @param string $message Message text
     * @return array{success:bool, error?:string}
     */
    public function sendWhatsApp(string $phone, string $message): array
    {
        if ($this->apiKey === '') {
            return ['success' => false, 'error' => 'API key not configured. Set it in Settings → Notification Settings.'];
        }

        $sender = $this->whatsappSender !== '' ? $this->whatsappSender : $this->senderId;
        $payload = json_encode([
            'text'         => $message,
            'type'         => 0,
            'sender'       => $sender,
            'destinations' => [['to' => $this->normalisePhone($phone)]],
        ]);

        $ch = curl_init('https://api.smsonlinegh.com/v5/message/whatsapp/send');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: key ' . $this->apiKey,
                'Host: api.smsonlinegh.com',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return ['success' => false, 'error' => "cURL error: $curlErr"];
        }

        // Detect empty / HTML response — means sender is not registered for WhatsApp
        $decoded = json_decode($result, true);
        if (empty($result) || $decoded === null) {
            return ['success' => false, 'error' => 'WhatsApp sender not registered. Log in to portal.smsonlinegh.com → WhatsApp and register a WhatsApp Business number as your sender, then set it in Settings → Notification Settings.'];
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'response' => $decoded];
        }
        return ['success' => false, 'error' => "HTTP $httpCode: $result"];
    }

    // ----------------------------------------------------------------- Email --
    /**
     * Send an HTML email using PHP mail().
     *
     * @param string $to       Recipient email address
     * @param string $subject  Email subject
     * @param string $htmlBody HTML email body
     * @return array{success:bool, error?:string}
     */
    public function sendEmail(string $to, string $subject, string $htmlBody): array
    {
        if ($this->emailFrom === '') {
            return ['success' => false, 'error' => 'Sender email not configured. Set it in Settings → Notification Settings.'];
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid recipient email address: ' . htmlspecialchars($to)];
        }

        $fromHeader = $this->emailFromName !== ''
            ? $this->emailFromName . ' <' . $this->emailFrom . '>'
            : $this->emailFrom;

        $headers = implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $fromHeader,
            'Reply-To: ' . $this->emailFrom,
            'X-Mailer: PHP/' . PHP_VERSION,
        ]);

        $sent = @mail($to, $subject, $htmlBody, $headers);
        if ($sent) {
            return ['success' => true];
        }
        return ['success' => false, 'error' => 'mail() returned false. Verify your server SMTP configuration.'];
    }

    // ---------------------------------------------------------------- Helpers --
    /**
     * Normalise Ghanaian phone numbers to local format expected by smsonlinegh.
     * The SDK accepts 0XXXXXXXXX (10 digits) or 233XXXXXXXXX.
     */
    private function normalisePhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        // Strip leading +
        $phone = ltrim($phone, '+');
        return $phone;
    }

    /**
     * Load notification settings from the system_settings table.
     *
     * @param \PDO $pdo
     * @return array
     */
    public static function loadSettings(\PDO $pdo): array
    {
        $keys = [
            'zenoph_api_key', 'zenoph_sender_id', 'zenoph_whatsapp_sender',
            'notification_email_from', 'notification_email_name', 'report_base_url',
        ];

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
        $stmt->execute($keys);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Return defaults for anything missing
        return array_merge([
            'zenoph_api_key'          => '',
            'zenoph_sender_id'        => 'SCHOOL',
            'zenoph_whatsapp_sender'  => '',
            'notification_email_from' => '',
            'notification_email_name' => 'School Notification',
            'report_base_url'         => '',
        ], $rows);
    }
}
