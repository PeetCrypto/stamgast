<?php
declare(strict_types=1);

/**
 * SMTP Transport — Native PHP SMTP client (no external dependencies)
 * Supports TLS, SSL, STARTTLS and SMTP AUTH LOGIN
 */
class SmtpTransport
{
    private array $config;
    /** @var resource|false */
    private $socket = false;
    private string $log = '';
    private string $lastError = '';

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Send an email via SMTP
     */
    public function send(array $emailData): bool
    {
        try {
            $this->lastError = '';
            $this->connect();
            $this->ehlo();

            $encryption = strtolower($this->config['smtp_encryption'] ?? 'tls');

            if ($encryption === 'tls' || $encryption === 'starttls') {
                $this->starttls();
                $this->ehlo(); // Re-send EHLO after STARTTLS
            }

            $this->authenticate();
            $this->envelope($emailData);
            $this->quit();

            return true;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log("SmtpTransport::send - " . $e->getMessage() . " | Log: " . $this->log);
            // Try to gracefully close
            try { $this->quit(); } catch (\Throwable $_) {}
            return false;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Get the conversation log (for debugging)
     */
    public function getLog(): string
    {
        return $this->log;
    }

    /**
     * Get the last error message
     */
    public function getLastError(): string
    {
        return $this->lastError;
    }

    // -------------------------------------------------------------------------
    // Private SMTP protocol methods
    // -------------------------------------------------------------------------

    private function connect(): void
    {
        $host = $this->config['smtp_host'];
        $port = (int)($this->config['smtp_port'] ?? 587);
        $encryption = strtolower($this->config['smtp_encryption'] ?? 'tls');

        $timeout = 30;

        if ($encryption === 'ssl') {
            // Direct SSL connection
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ]);
            $this->socket = @stream_socket_client(
                "ssl://{$host}:{$port}",
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_CONNECT,
                $context
            );
        } else {
            // Plain connection — create with SSL context for later STARTTLS upgrade
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ]);
            $this->socket = @stream_socket_client(
                "tcp://{$host}:{$port}",
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_CONNECT,
                $context
            );
        }

        if (!$this->socket) {
            throw new \RuntimeException("Cannot connect to {$host}:{$port} — [{$errno}] {$errstr}");
        }

        stream_set_timeout($this->socket, $timeout);

        // Read server greeting
        $this->read(220);
    }

    private function ehlo(): void
    {
        $hostname = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $this->sendCommand("EHLO {$hostname}", 250);
    }

    private function starttls(): void
    {
        $this->sendCommand("STARTTLS", 220);

        // Try multiple crypto methods for broader compatibility
        $cryptoMethods = [
            STREAM_CRYPTO_METHOD_TLS_CLIENT,
            STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLS_CLIENT,
            STREAM_CRYPTO_METHOD_SSLv23_CLIENT,
        ];

        $lastError = '';
        foreach ($cryptoMethods as $method) {
            $result = @stream_socket_enable_crypto($this->socket, true, $method);
            if ($result) {
                return; // TLS handshake succeeded
            }
            $lastError = error_get_last()['message'] ?? 'unknown error';
        }

        throw new \RuntimeException("Failed to enable TLS encryption: {$lastError}");
    }

    private function authenticate(): void
    {
        $user = $this->config['smtp_user'] ?? '';
        $pass = $this->config['smtp_pass'] ?? '';

        if (empty($user)) {
            return; // No auth needed
        }

        // Try AUTH LOGIN first, fall back to AUTH PLAIN
        try {
            $this->sendCommand("AUTH LOGIN", 334);
            $this->sendCommand(base64_encode($user), 334);
            $this->sendCommand(base64_encode($pass), 235);
        } catch (\Throwable $e) {
            // AUTH LOGIN failed — try AUTH PLAIN
            // PLAIN format: \0username\0password (base64 encoded)
            $this->sendCommand("AUTH PLAIN " . base64_encode("\0{$user}\0{$pass}"), 235);
        }
    }

    private function envelope(array $emailData): void
    {
        $fromEmail = $this->config['from_email'] ?? 'no-reply@regulr.vip';
        $fromName  = $this->config['from_name'] ?? 'REGULR';
        $to        = $emailData['to'];
        $subject   = $emailData['subject'] ?? '(no subject)';
        $html      = $emailData['html_content'] ?? '';
        $text      = $emailData['text_content'] ?? '';

        // MAIL FROM
        $this->sendCommand("MAIL FROM:<{$fromEmail}>", 250);

        // RCPT TO
        $this->sendCommand("RCPT TO:<{$to}>", [250, 251]);

        // DATA
        $this->sendCommand("DATA", 354);

        // Build email headers and body
        $boundary = '----=_Part_' . md5(uniqid((string)mt_rand(), true));

        $headers = [];
        $headers[] = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>";
        $headers[] = "To: <{$to}>";
        $headers[] = "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=";
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Date: " . date('r');
        $headers[] = "Message-ID: <" . md5(uniqid((string)time(), true)) . "@{$fromEmail}>";

        if (!empty($text) && !empty($html)) {
            // Multipart: plain text + HTML
            $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";

            $body = "--{$boundary}\r\n"
                  . "Content-Type: text/plain; charset=UTF-8\r\n"
                  . "Content-Transfer-Encoding: base64\r\n\r\n"
                  . chunk_split(base64_encode($text))
                  . "\r\n--{$boundary}\r\n"
                  . "Content-Type: text/html; charset=UTF-8\r\n"
                  . "Content-Transfer-Encoding: base64\r\n\r\n"
                  . chunk_split(base64_encode($html))
                  . "\r\n--{$boundary}--";
        } elseif (!empty($html)) {
            // HTML only
            $headers[] = "Content-Type: text/html; charset=UTF-8";
            $headers[] = "Content-Transfer-Encoding: base64";
            $body = chunk_split(base64_encode($html));
        } else {
            // Plain text only
            $headers[] = "Content-Type: text/plain; charset=UTF-8";
            $headers[] = "Content-Transfer-Encoding: base64";
            $body = chunk_split(base64_encode($text ?: ''));
        }

        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";

        $this->sendRaw($message);
        $this->read(250);
    }

    private function quit(): void
    {
        if ($this->socket) {
            try {
                $this->sendCommand("QUIT", 221);
            } catch (\Throwable $_) {}
        }
    }

    private function disconnect(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = false;
        }
    }

    // -------------------------------------------------------------------------
    // Low-level I/O
    // -------------------------------------------------------------------------

    private function sendCommand(string $command, int|array $expectedCode): string
    {
        $this->sendRaw($command);

        return $this->read($expectedCode);
    }

    private function sendRaw(string $data): void
    {
        $data .= "\r\n";
        $written = @fwrite($this->socket, $data);

        if ($written === false) {
            throw new \RuntimeException("Failed to write to socket");
        }

        $this->log .= ">> " . trim($data) . "\n";
    }

    private function read(int|array $expectedCode): string
    {
        $response = '';
        $startTime = time();

        while (true) {
            if ((time() - $startTime) > 30) {
                throw new \RuntimeException("Timeout reading from SMTP server");
            }

            $line = @fgets($this->socket, 515);

            if ($line === false) {
                // Could be end of stream or timeout
                if (empty($response)) {
                    throw new \RuntimeException("No response from SMTP server");
                }
                break;
            }

            $response .= $line;
            $this->log .= "<< " . trim($line) . "\n";

            // SMTP multi-line responses: "250-SIZE ..." continues, "250 OK" ends
            if (preg_match('/^\d{3} /', $line)) {
                break;
            }
        }

        // Extract response code
        $code = (int)substr(trim($response), 0, 3);

        $expectedCodes = is_array($expectedCode) ? $expectedCode : [$expectedCode];

        if (!in_array($code, $expectedCodes, true)) {
            throw new \RuntimeException(
                "SMTP error: expected " . implode('/', $expectedCodes)
                . ", got [{$code}] — " . trim($response)
            );
        }

        return $response;
    }
}
