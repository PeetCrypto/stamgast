<?php
declare(strict_types=1);

/**
 * PlatformSetting Model
 * Key-value store for platform-level configuration
 * (Mollie Connect credentials, feature flags, etc.)
 */
class PlatformSetting
{
    private PDO $db;

    /** Keys that are allowed to be set via the API/UI */
    public const ALLOWED_KEYS = [
        'mollie_connect_api_key',
        'mollie_connect_client_id',
        'mollie_connect_client_secret',
        'mollie_mode_default',
    ];

    /** Keys whose values should be masked in API responses */
    private const SECRET_KEYS = [
        'mollie_connect_api_key',
        'mollie_connect_client_secret',
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get a single setting value by key
     */
    public function get(string $key): ?string
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT setting_value FROM platform_settings WHERE setting_key = :key"
            );
            $stmt->execute([':key' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row['setting_value'] : null;
        } catch (\Throwable $e) {
            error_log("PlatformSetting::get({$key}) - " . $e->getMessage());
            return null;
        }
    }

    /**
     * Set (upsert) a single setting value
     */
    public function set(string $key, string $value): bool
    {
        if (!in_array($key, self::ALLOWED_KEYS, true)) {
            error_log("PlatformSetting::set - blocked disallowed key: {$key}");
            return false;
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO platform_settings (setting_key, setting_value)
                VALUES (:key, :value)
                ON DUPLICATE KEY UPDATE setting_value = :value2
            ");
            $stmt->execute([':key' => $key, ':value' => $value, ':value2' => $value]);
            return true;
        } catch (\Throwable $e) {
            error_log("PlatformSetting::set({$key}) - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all settings as associative array [key => value]
     */
    public function getAll(): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT setting_key, setting_value, encrypted FROM platform_settings ORDER BY id"
            );
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log("PlatformSetting::getAll - " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get multiple settings by keys as [key => value]
     */
    public function getMultiple(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }
        return $result;
    }

    /**
     * Get all settings with secret values masked for safe display
     */
    public function getAllMasked(): array
    {
        $settings = $this->getAll();
        foreach ($settings as &$row) {
            if (in_array($row['setting_key'], self::SECRET_KEYS, true) && !empty($row['setting_value'])) {
                $row['setting_value'] = '••••••••';
                $row['is_masked'] = true;
            } else {
                $row['is_masked'] = false;
            }
        }
        unset($row);
        return $settings;
    }

    /**
     * Check if a non-empty value exists for a key
     */
    public function has(string $key): bool
    {
        $value = $this->get($key);
        return $value !== null && $value !== '';
    }
}
