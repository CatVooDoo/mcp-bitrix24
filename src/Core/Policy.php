<?php
declare(strict_types=1);
namespace Bitrix24Mcp\Core;

final class Policy
{
    public readonly array $profile;
    public function __construct(?array $profile = null)
    {
        $this->profile = $profile ?? json_decode((string)file_get_contents(Env::get('B24_PROFILE_FILE',Env::root().'/config/portal.json')),true,512,JSON_THROW_ON_ERROR);
        if (empty($this->profile['portal_id'])) { throw new \RuntimeException('portal_id is required'); }
    }
    public function get(string $key, mixed $default = null): mixed { return $this->profile[$key] ?? $default; }
    public function external(string $dialog, ?Store $store = null): void
    {
        if (in_array($dialog,$this->get('external_dialogs',[]),true)) { return; }
        if ($store !== null) {
            $cursor = $store->get('source_cursors', 'openline_dialog:' . $dialog);
            if ($cursor && $this->externalOpenLineAllowed((string)($cursor['data']['entity_id'] ?? ''))) { return; }
        }
        throw new \DomainException('External channel is not allowed');
    }
    public function externalOpenLineAllowed(?string $entityId): bool
    {
        if ($entityId === null || $entityId === '') { return false; }
        foreach ($this->get('external_openline_prefixes', []) as $prefix) {
            if ($prefix !== '' && str_starts_with($entityId, (string)$prefix)) { return true; }
        }
        return false;
    }
    public function internal(string $dialog, int $employee): void
    {
        if (!in_array($employee,$this->get('employee_ids',[]),true)) { throw new \DomainException('Employee is not allowed'); }
        if (in_array($dialog,$this->get('external_dialogs',[]),true)) { throw new \DomainException('Client channel cannot be internal'); }
        if ($dialog !== (string)$employee && !in_array($dialog,$this->get('internal_dialogs',[]),true)) { throw new \DomainException('Internal channel is not allowed'); }
    }
    public function employee(int $id): bool { return in_array($id,$this->get('employee_ids',[]),true); }
    public function capability(string $name): void
    {
        if (!in_array($name,$this->get('capabilities',[]),true)) { throw new \DomainException('Capability denied: '.$name); }
    }
    public function mail(int $mailbox): void
    {
        if (!in_array($mailbox,$this->get('mailbox_ids',[]),true)) { throw new \DomainException('Mailbox is not allowed'); }
    }
}
