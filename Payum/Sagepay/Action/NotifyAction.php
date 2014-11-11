<?php

namespace Ledjin\Bundle\SyliusSagepayBundle\Payum\Sagepay\Action;

use Doctrine\Common\Persistence\ObjectManager;
use Payum\Core\Bridge\Symfony\Reply\HttpResponse;
use Payum\Core\Request\Notify;
use Ledjin\Sagepay\Api;
use Payum\Core\Exception\RequestNotSupportedException;
use SM\Factory\FactoryInterface;
use Sylius\Bundle\PayumBundle\Payum\Action\AbstractPaymentStateAwareAction;
use Sylius\Bundle\PayumBundle\Payum\Request\GetStatus;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Request\GetHttpRequest;
use Ledjin\Sagepay\Api\State\StateInterface;
use Ledjin\Sagepay\Api\Reply\NotifyResponse;

class NotifyAction extends AbstractPaymentStateAwareAction
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
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * @var string
     */
    protected $identifier;

    public function __construct(
        Api $api,
        RepositoryInterface $paymentRepository,
        EventDispatcherInterface $eventDispatcher,
        ObjectManager $objectManager,
        FactoryInterface $factory,
        $identifier
    ) {
        parent::__construct($factory);

        $this->api               = $api;
        $this->paymentRepository = $paymentRepository;
        $this->eventDispatcher   = $eventDispatcher;
        $this->objectManager     = $objectManager;
        $this->identifier        = $identifier;
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

        // invalidate:
        // - we process only replied and notified payments
        if (!isset($model['state']) ||
            !in_array(
                $model['state'],
                array(
                    StateInterface::STATE_REPLIED,
                    StateInterface::STATE_NOTIFIED,
                )
            )
        ) {
            // return;
        }

        // $details = $request->getNotification();
        $httpRequest = new GetHttpRequest;
        $this->payment->execute($httpRequest);

        if ($httpRequest->method != 'POST') {
            throw new BadRequestHttpException('Request method must be set correctly');
        }

        $notification = $httpRequest->request;

        if (empty($notification['VendorTxCode'])) {
            throw new BadRequestHttpException('VendorTxCode cannot be guessed');
        }

        $payment = $this->paymentRepository->findOneBy(array($this->identifier => $notification['VendorTxCode']));

        if (null === $payment) {
            throw new BadRequestHttpException('Paymenet cannot be retrieved.');
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
