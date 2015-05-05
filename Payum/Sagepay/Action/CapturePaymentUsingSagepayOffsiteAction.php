<?php

namespace Ledjin\Bundle\SyliusSagepayBundle\Payum\Sagepay\Action;

use Payum\Core\Exception\LogicException;
use Payum\Core\Security\TokenInterface;
use Payum\Core\Bridge\Symfony\Security\TokenFactory;
use Sylius\Bundle\PayumBundle\Payum\Action\AbstractCapturePaymentAction;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Currency\Converter\CurrencyConverterInterface;
use Symfony\Component\HttpFoundation\Request;

class CapturePaymentUsingSagepayOffsiteAction extends AbstractCapturePaymentAction
{
    /** @var TokenFactory  */
    protected $tokenFactory;

    /** @var  CurrencyConverterInterface */
    protected $currencyConverter;

    public function setTokenFactory(TokenFactory $tokenFactory)
    {
        $this->tokenFactory = $tokenFactory;
    }

    public function setCurrencyConverter(CurrencyConverterInterface $currencyConverter)
    {
        $this->currencyConverter = $currencyConverter;
    }

    protected function composeDetails(PaymentInterface $payment, TokenInterface $token)
    {
        if ($payment->getDetails()) {
            return;
        }

        $order = $payment->getOrder();

        $details = array();

        $total = $this->currencyConverter->convert($order->getTotal(), $order->getCurrency());

        $details = array(
            'VendorTxCode' => $payment->getId(),
            'Amount' => round($total / 100, 2),
            'Currency' => $order->getCurrency(),
            'Description' => sprintf('Order containing %d items for a total of %01.2f', $order->getItems()->count(), round($total / 100, 2)),
            'NotificationURL' => $this->tokenFactory
                ->createNotifyToken(
                    $token->getPaymentName(),
                    $payment->getOrder()
                )->getTargetUrl(),
            'BillingSurname' => $order->getBillingAddress()->getLastName(),
            'BillingFirstnames' => $order->getBillingAddress()->getFirstName(),
            'BillingAddress1' => $order->getBillingAddress()->getStreet(),
            'BillingCity' => $order->getBillingAddress()->getCity(),
            'BillingPostCode' => $order->getBillingAddress()->getPostcode(),
            'BillingCountry' => $order->getBillingAddress()->getCountry()->getIsoName(),
            'DeliverySurname' => $order->getShippingAddress()->getLastName(),
            'DeliveryFirstnames' => $order->getShippingAddress()->getFirstName(),
            'DeliveryAddress1' => $order->getShippingAddress()->getStreet(),
            'DeliveryCity' => $order->getShippingAddress()->getCity(),
            'DeliveryPostCode' => $order->getShippingAddress()->getPostcode(),
            'DeliveryCountry' => $order->getShippingAddress()->getCountry()->getIsoName(),
        );

        $payment->setDetails($details);
    }
}
