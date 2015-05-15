<?php

namespace Ledjin\Bundle\SyliusSagepayBundle\Payum\Sagepay\Action;

use Doctrine\Common\Persistence\ObjectManager;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Exception\UnsupportedApiException;
use Payum\Core\Request\Notify;
use Ledjin\Sagepay\Api;
use Payum\Core\Exception\RequestNotSupportedException;
use SM\Factory\FactoryInterface;
use Sylius\Bundle\PayumBundle\Payum\Action\AbstractPaymentStateAwareAction;
use Sylius\Bundle\PayumBundle\Payum\Request\GetStatus;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Payum\Core\Request\GetHttpRequest;
use Ledjin\Sagepay\Api\State\StateInterface;
use Ledjin\Sagepay\Api\Reply\NotifyResponse;

class NotifyAction extends AbstractPaymentStateAwareAction implements ApiAwareInterface
{
    /**
     * @var Api
     */
    protected $api;

    /**
     * @var RepositoryInterface
     */
    protected $paymentRepository;

    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * @var string
     */
    protected $identifier;

    public function __construct(
        RepositoryInterface $paymentRepository,
        ObjectManager $objectManager,
        FactoryInterface $factory,
        $identifier
    ) {
        parent::__construct($factory);

        $this->paymentRepository = $paymentRepository;
        $this->objectManager     = $objectManager;
        $this->identifier        = $identifier;
    }

    /**
     * {@inheritDoc}
     */
    public function setApi($api)
    {
        if (!$api instanceof Api) {
            throw new UnsupportedApiException('Not supported.');
        }

        $this->api = $api;
    }

    /**
     * {@inheritDoc}
     *
     * @param $request Notify
     */
    public function execute($request)
    {
        if (!$this->supports($request)) {
            throw RequestNotSupportedException::createActionNotSupported($this, $request);
        }

        $this->payment->execute($httpRequest = new GetHttpRequest());

        if ($httpRequest->method != 'POST') {
            throw new BadRequestHttpException('Request method must be set correctly');
        }

        $notification = $httpRequest->request;

        if (empty($notification['VendorTxCode'])) {
            throw new BadRequestHttpException('VendorTxCode cannot be guessed');
        }

        $payment = $this->paymentRepository->findOneBy(array($this->identifier => $notification['VendorTxCode']));

        if (null === $payment) {
            throw new BadRequestHttpException('Payment cannot be retrieved.');
        }

        $details = $payment->getDetails();

        $status = Api::STATUS_OK;
        $details['state'] = StateInterface::STATE_NOTIFIED;
        $redirectUrl = $details['afterUrl'];
        
        if ($this->api->tamperingDetected((array) $notification, (array) $details)) {
            $status = Api::STATUS_INVALID;
            $statusDetails = "Tampering detected. Wrong hash.";
        } else {
            if ($notification['Status'] == Api::STATUS_OK) {
                $details['state'] = StateInterface::STATE_CONFIRMED;
            } elseif ($notification['Status'] == Api::STATUS_PENDING) {
                $details['state'] = StateInterface::STATE_REPLIED;
            } else {
                $details['state'] = StateInterface::STATE_ERROR;
            }

            $statusDetails = 'Transaction processed';

            if ($notification['Status'] == Api::STATUS_ERROR
                && isset($notification['Vendor'])
                && isset($notification['VendorTxCode'])
                && isset($notification['StatusDetail'])
            ) {
                $status = Api::STATUS_ERROR;
                $statusDetails = 'Status of ERROR is seen, together with Vendor, VendorTxCode and the StatusDetail.';
            }

        }

        $details['notification'] = (array) $notification;
        $payment->setDetails($details);

        $params = array(
            'Status' => $status,
            'StatusDetails' => $statusDetails,
            'RedirectURL' => $redirectUrl,
            );

        $status = new GetStatus($payment);
        $this->payment->execute($status);

        $nextState = $status->getValue();

        $this->updatePaymentState($payment, $nextState);

        $this->objectManager->flush();

        throw new NotifyResponse($params);
    }

    /**
     * {@inheritDoc}
     */
    public function supports($request)
    {
        return $request instanceof Notify;
    }


}
