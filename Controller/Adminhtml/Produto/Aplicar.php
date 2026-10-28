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

/** Writes only the fields the admin user ticked in the review table. */
class Aplicar extends Action implements HttpPostActionInterface
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
            if (!$this->_authorization->isAllowed('Magento_Catalog::products')) {
                throw new LocalizedException(__('You are not allowed to edit products.'));
            }
            $suggested = json_decode((string) $this->getRequest()->getParam('sugerido'), true, 8);
            if (!is_array($suggested)) {
                throw new LocalizedException(__('Look the product up again.'));
            }
            $fields = (array) $this->getRequest()->getParam('campos', []);
            $changed = $this->applier->apply((int) $this->getRequest()->getParam('id'), $fields, $suggested);

            return $result->setData(['ok' => true, 'alterados' => $changed]);
        } catch (LocalizedException $e) {
            return $result->setData(['ok' => false, 'erro' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('SI5 Global apply: ' . $e->getMessage());

            return $result->setData(['ok' => false, 'erro' => (string) __('Unexpected error. See the Magento log.')]);
        }
    }
}
