<?php
/**
 * RayPay payment gateway
 *
 * @developer Hanieh Ramzanpour
 * @publisher RayPay
 * @copyright (C) 2021 RayPay
 * @license http://www.gnu.org/licenses/gpl-2.0.html GPLv2 or later
 *
 * https://raypay.ir
 */
namespace RayPay\RayPay;

use XF\AddOn\AbstractSetup;

class Setup extends AbstractSetup
{
    public function upgrade(array $stepParams = [])
    {
        $this->uninstall();
        $this->install();
    }

    public function install(array $stepParams = [])
    {
        $entity = \XF::em()->create('XF:PaymentProvider');
        $entity->bulkSet(
            [
                'provider_id' => "RayPay",
                'provider_class' => "RayPay\\RayPay\\RayPay",
                'addon_id' => "RayPay/RayPay"
            ]
        );
        $entity->save();
    }

    public function uninstall(array $stepParams = [])
    {
        $entity = \XF::em()->find('XF:PaymentProvider', 'RayPay');
        if (!empty($entity)) {
            $entity->delete();
        }
    }
}
