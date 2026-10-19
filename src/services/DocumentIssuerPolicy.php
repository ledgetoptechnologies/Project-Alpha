<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;

/** Immutable issuer presentation; mail transport remains company-controlled. */
final class DocumentIssuerPolicy
{
    public const COMPANY = 'company';
    public const CREATOR = 'creator';

    public static function mode(?string $override, ?string $globalDefault = null): string
    {
        $mode = $override === null || $override === '' || $override === 'default'
            ? ($globalDefault ?: self::COMPANY) : $override;
        if (!in_array($mode, [self::COMPANY, self::CREATOR], true)) {
            throw new DomainException('Choose company or original creator for the document issuer.');
        }
        return $mode;
    }

    /**
     * Caller must supply the original creator, never the current editor/sender.
     * Personal profile fields cannot replace company branding, postal address,
     * or the authenticated sending mailbox.
     */
    public static function resolve(array $config, ?array $originalCreator, ?string $override = null): array
    {
        $mode = self::mode($override, isset($config['document_issuer_mode']) ? (string)$config['document_issuer_mode'] : null);
        $company = trim((string)($config['from_company'] ?? '')) ?: (trim((string)($config['brand_name'] ?? '')) ?: 'Project Alpha');
        $sender = ['enabled' => false, 'name' => $company, 'company' => $company];
        foreach (['address_line1', 'address_line2', 'city', 'state', 'postal', 'country', 'phone', 'email'] as $key) {
            $sender[$key] = trim((string)($config['from_' . $key] ?? ''));
        }
        $replyTo = null;
        if ($mode === self::CREATOR && $originalCreator !== null) {
            $custom = !empty($originalCreator['document_sender_enabled']);
            $name = $custom ? trim((string)($originalCreator['document_sender_name'] ?? '')) : '';
            $name = $name ?: trim((string)($originalCreator['display_name'] ?? $originalCreator['username'] ?? ''));
            // Avoid silently falling back to an unrelated configured person's name.
            if ($name !== '') {
                $sender['name'] = $name;
                $sender['enabled'] = true;
            }
            $email = $custom ? trim((string)($originalCreator['document_sender_email'] ?? '')) : '';
            $email = $email ?: trim((string)($originalCreator['email'] ?? ''));
            if (!str_contains($email, "\r") && !str_contains($email, "\n") && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $replyTo = $email;
                $sender['email'] = $email;
            }
            if ($custom && trim((string)($originalCreator['document_sender_phone'] ?? '')) !== '') {
                $sender['phone'] = trim((string)$originalCreator['document_sender_phone']);
            }
        }
        return [
            'schema_version' => 1,
            'mode' => $mode,
            'original_creator_id' => isset($originalCreator['id']) ? (int)$originalCreator['id'] : null,
            'sender' => $sender,
            'email' => [
                // Always use verified provider/company transport configuration.
                'from_address' => trim((string)($config['from_email'] ?? '')),
                'display_name' => $mode === self::CREATOR && $sender['enabled'] ? $sender['name'] . ' · ' . $company : $company,
                'reply_to' => $replyTo,
            ],
        ];
    }
}
