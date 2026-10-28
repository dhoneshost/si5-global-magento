<?php
/**
 * @license OSL-3.0
 */
declare(strict_types=1);

namespace Si5\GlobalCatalog\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Si5\GlobalCatalog\Model\Applier;

/** "SI5 Global" section of the product form. */
class Painel extends Template
{
    protected $_template = 'Si5_GlobalCatalog::painel.phtml';

    private Applier $applier;

    public function __construct(Context $context, Applier $applier, array $data = [])
    {
        parent::__construct($context, $data);
        $this->applier = $applier;
    }

    public function productId(): int
    {
        return (int) $this->getRequest()->getParam('id');
    }

    public function isEnabled(): bool
    {
        return $this->applier->isEnabled() && $this->_authorization->isAllowed('Si5_GlobalCatalog::lookup');
    }

    public function hasToken(): bool
    {
        return $this->applier->hasToken();
    }

    public function jsConfig(): array
    {
        return [
            'consultar' => $this->getUrl('si5global/produto/consultar', ['id' => $this->productId()]),
            'aplicar' => $this->getUrl('si5global/produto/aplicar', ['id' => $this->productId()]),
            'formKey' => $this->getFormKey(),
            'rotulos' => [
                'nome' => (string) __('Name'), 'marca' => (string) __('Brand'), 'peso' => (string) __('Weight'),
                'imagem' => (string) __('Picture'), 'ncm' => 'NCM', 'cest' => 'CEST',
            ],
            'textos' => [
                'consultando' => (string) __('Looking up SI5 Global…'),
                'marque' => (string) __('Tick what you want to replace and click Apply.'),
                'nenhum' => (string) __('Tick at least one field.'),
                'aplicando' => (string) __('Applying…'),
                'atualizado' => (string) __('Updated: %1. Reload the page to see the changes.'),
                'falha' => (string) __('Request failed. Try again.'),
                'referencia' => (string) __('NCM and CEST are suggestions from the catalogue. Confirm them with your accountant.'),
            ],
        ];
    }

    public function configUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'si5global']);
    }
}
