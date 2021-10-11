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

use XF\Entity\PaymentProfile;
use XF\Entity\PurchaseRequest;
use XF\Mvc\Controller;
use XF\Payment\AbstractProvider;
use XF\Payment\CallbackState;
use XF\Purchasable\Purchase;

class RayPay extends AbstractProvider
{
    public function getTitle()
    {
        return 'RayPay';
    }

    public function verifyConfig(array &$options, &$errors = [])
    {
        if (empty($options['raypay_user_id'])) {
            $errors[] = \XF::phrase('you_must_provide_raypay_user_id');
        }
        if (empty($options['raypay_marketing_id'])) {
            $errors[] = \XF::phrase('you_must_provide_raypay_marketing_id');
        }
        return (empty($errors) ? false : true);
    }

    public function initiatePayment(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase)
    {
        $user_id = $purchase->paymentProfile->options['raypay_user_id'];
        $marketing_id = $purchase->paymentProfile->options['raypay_marketing_id'];
        $invoice_id             = round(microtime(true) * 1000);
        $sandbox = (!empty($purchase->paymentProfile->options['raypay_sandbox'])
            && $purchase->paymentProfile->options['raypay_sandbox'] == 1)
            ? true : false;
        $amount = strval(round($purchase->cost , 0));
        $desc = ($purchase->title ?: ('Invoice#' . $purchaseRequest->request_key));
        $callback = $this->getCallbackUrl() . '&order_id='. $purchaseRequest->request_key . '&transaction_id='. $invoice_id;

        if (empty($amount)) {
            return 'واحد پول انتخاب شده پشتیبانی نمی شود.';
        }

        $data = array(
            'factorNumber' => strval($purchaseRequest->request_key),
            'amount' => $amount,
            'userID' => $user_id,
            'marketingID' => $marketing_id,
            'invoiceID'    => strval($invoice_id),
            'fullName' => $purchase->purchaser->username,
            'email' => $purchase->purchaser->email,
            'desc' => $desc,
            'redirectUrl' => $callback,
            'enableSandBox' => $sandbox
        );

        $url = 'https://api.raypay.ir/raypay/api/v1/payment/pay';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));

        $result = curl_exec($ch);
        $result = json_decode($result);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_status != 200) {
            if ( !empty($result->Message) && !empty($result->StatusCode)) {
                $message = sprintf('خطا هنگام ایجاد تراکنش. وضعیت خطا: %s - کد خطا: %s - پیام خطا: %s', $http_status, $result->StatusCode, $result->Message);
                return $controller->error($message);
            }
            $message = sprintf('خطا هنگام ایجاد تراکنش. وضعیت خطا: %s', $http_status);
            return $controller->error($message);

        } else {
            @session_start();
            $_SESSION[$invoice_id . '1'] = $purchase->returnUrl;
            $_SESSION[$invoice_id . '2'] = $purchase->cancelUrl;
            setcookie($invoice_id . '1', $purchase->returnUrl, time() + 1200, '/');
            setcookie($invoice_id . '2', $purchase->cancelUrl, time() + 1200, '/');
            $link='https://my.raypay.ir/ipg?token=' . $result->Data;
            return $controller->redirect($link, '');
        }
    }

    public function supportsRecurring(PaymentProfile $paymentProfile, $unit, $amount, &$result = self::ERR_NO_RECURRING)
    {
        return true;
    }

    public function setupCallback(\XF\Http\Request $request)
    {
        $state = new CallbackState();
        $state->transactionId = $request->filter('transaction_id', 'str');
        $state->costAmount = $request->filter('amount', 'unum');
        if (empty($state->costAmount)) {
            $state->noAmount = true;
        }
        $state->taxAmount = 0;
        $state->costCurrency = 'IRR';
        $state->requestKey = $request->filter('order_id', 'str');
        $state->ip = $request->getIp();
        $state->_POST = $_REQUEST;
        return $state;
    }

    public function validateTransaction(CallbackState $state)
    {
        if (!$state->requestKey) {
            $state->logType = 'info';
            $state->logMessage = 'No purchase request key. Unrelated payment, no action to take.' . json_encode($state);
            return false;
        }
        if (!$state->transactionId) {
            $state->logType = 'info';
            $state->logMessage = 'No transaction or subscriber ID. No action to take.';
            return false;
        }
        return parent::validateTransaction($state);
    }

    public function validateCost(CallbackState $state)
    {
        return true;
    }

    public function getPaymentResult(CallbackState $state)
    {
    }

    public function prepareLogData(CallbackState $state)
    {
        $state->logDetails = $state->_POST;
    }

    public function completeTransaction(CallbackState $state) {
        @session_start();
        $router    = \XF::app()->router( 'public' );
        $returnUrl = !empty($_SESSION[$state->transactionId . '1']) ? $_SESSION[$state->transactionId . '1'] : '';
        $cancelUrl = !empty($_SESSION[$state->transactionId . '2']) ? $_SESSION[$state->transactionId . '2'] : '';
        if ( empty( $returnUrl ) )
        {
            $returnUrl = $_COOKIE[$state->transactionId . '1'];
        }
        if ( empty( $cancelUrl ) )
        {
            $cancelUrl = $_COOKIE[$state->transactionId . '2'];
        }
        if ( empty( $returnUrl ) )
        {
            $returnUrl = $router->buildLink( 'canonical:account/upgrade-purchase' );
        }
        if ( empty( $cancelUrl ) )
        {
            $cancelUrl = $router->buildLink( 'canonical:account/upgrades' );
        }
        unset( $_SESSION[$state->transactionId . '1'], $_SESSION[$state->transactionId . '2'] );
        setcookie( $state->transactionId . '1', './?', time(), '/' );
        setcookie( $state->transactionId . '2', './?', time(), '/' );

            $url     = $cancelUrl;
            $ch = curl_init('https://api.raypay.ir/raypay/api/v1/payment/verify');
            curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $_POST ) );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
            curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json',) );

            $result      = curl_exec( $ch );
            $result      = json_decode( $result );
            $http_status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            curl_close( $ch );


            if ( $http_status != 200 )
            {
                $state->logType    = 'error';
                $state->logMessage = sprintf( 'خطا هنگام بررسی وضعیت تراکنش. وضعیت خطا: %s - کدخطا: %s - پیام خطا: %s', $http_status, $result->StatusCode, $result->Message );
            }
            else
            {
                $verify_status   = empty( $result->Data->Status ) ? NULL : $result->Data->Status;
                $verify_invoice_id = empty( $result->Data->InvoiceID ) ? NULL : $result->Data->InvoiceID;

                if ( empty( $verify_invoice_id ) || empty( $verify_status ) ||  $verify_status != 1 )
                {
                    $state->paymentResult = CallbackState::PAYMENT_REINSTATED;
                    $state->logType    = 'error';
                    $state->logMessage = $this->raypay_get_failed_message( $state->paymentProfile->options['raypay_failed_message'], $verify_invoice_id);
                    $url = $cancelUrl;
                }
                else
                {
                    $state->paymentResult = CallbackState::PAYMENT_RECEIVED;
                    $state->logType    = 'success';
                    $state->logMessage = $this->raypay_get_success_message( $state->paymentProfile->options['raypay_success_message'], $verify_invoice_id);
                    parent::completeTransaction( $state );
                    $url = $returnUrl;
                }
            }
        @header('location: ' . $url);
        exit;
    }


    public function raypay_get_failed_message($failed_massage, $invoice_id)
    {
        return str_replace(["{invoice_id}"], [$invoice_id], $failed_massage);
    }

    public function raypay_get_success_message($success_massage, $invoice_id)
    {
        return str_replace(["{invoice_id}"], [$invoice_id], $success_massage);
    }

}
