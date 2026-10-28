<?php
/**
 * @license OSL-3.0
 */
declare(strict_types=1);

namespace Si5\GlobalCatalog\Controller\Adminhtml\Produto;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Si5\GlobalCatalog\Model\Applier;

/** Looks the product barcode up in SI5 Global and returns current × suggested values. Writes nothing. */
class Consultar extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Si5_GlobalCatalog::lookup';

    private JsonFactory $json;
    private Applier $applier;
    private LoggerInterface $logger;

    public function __construct(Context $context, JsonFactory $json, Applier $applier, LoggerInterface $logger)
    {
        parent::__construct($context);
        $this->json = $json;
        $this->applier = $applier;
        $this->logger = $logger;
    }

    public function execute(): Json
    {
        $result = $this->json->create();
        $result->setHeader('Cache-Control', 'no-store', true);
        try {
            $r = $this->applier->lookup((int) $this->getRequest()->getParam('id'));

            return $result->setData(['ok' => true] + $r);
        } catch (LocalizedException $e) {
            return $result->setData(['ok' => false, 'erro' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('SI5 Global lookup: ' . $e->getMessage());

            return $result->setData(['ok' => false, 'erro' => (string) __('Unexpected error. See the Magento log.')]);
        }
    }
}
