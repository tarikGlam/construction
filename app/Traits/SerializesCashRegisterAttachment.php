<?php

namespace App\Traits;

use App\Services\Domain\CashRegisterDomainService;

trait SerializesCashRegisterAttachment
{
    public function save(array $options = [])
    {
        $isNewRegisterAttachment = $this->cash_register_id !== null
            && (!$this->exists || $this->isDirty('cash_register_id'));

        if (!$isNewRegisterAttachment) {
            return parent::save($options);
        }

        return app(CashRegisterDomainService::class)->withLockedOpenRegister(
            (int) $this->cash_register_id,
            fn () => parent::save($options),
            $this->closedRegisterAttachmentMessage()
        );
    }

    protected function closedRegisterAttachmentMessage(): string
    {
        return __('db.The cash register closed before this financial movement could be recorded. Reopen the register and retry.');
    }
}
