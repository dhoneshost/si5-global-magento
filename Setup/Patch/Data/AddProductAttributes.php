<?php
/**
 * @license OSL-3.0
 */
declare(strict_types=1);

namespace Si5\GlobalCatalog\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

/**
 * Product attributes used by the module: barcode (GTIN/EAN) and the Brazilian fiscal codes NCM and CEST,
 * in an "SI5 Global" group of every attribute set.
 */
class AddProductAttributes implements DataPatchInterface, PatchRevertableInterface
{
    public const GROUP = 'SI5 Global';
    public const ATTRIBUTES = [
        'si5_gtin' => ['label' => 'GTIN / EAN', 'length' => 14, 'sort' => 10, 'note' => 'Barcode (EAN-8, EAN-13, UPC-A or GTIN-14).'],
        'si5_ncm' => ['label' => 'NCM', 'length' => 8, 'sort' => 20, 'note' => 'Brazilian NCM (8 digits). Suggestion from the catalogue: confirm with your accountant.'],
        'si5_cest' => ['label' => 'CEST', 'length' => 7, 'sort' => 30, 'note' => 'Brazilian CEST (7 digits). Suggestion from the catalogue: confirm with your accountant.'],
    ];

    private ModuleDataSetupInterface $moduleDataSetup;
    private EavSetupFactory $eavSetupFactory;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup, EavSetupFactory $eavSetupFactory)
    {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->eavSetupFactory = $eavSetupFactory;
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        /** @var EavSetup $eav */
        $eav = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $entity = Product::ENTITY;
        foreach (self::ATTRIBUTES as $code => $a) {
            if ($eav->getAttributeId($entity, $code)) {
                continue;
            }
            $eav->addAttribute($entity, $code, [
                'type' => 'varchar',
                'label' => $a['label'],
                'input' => 'text',
                'frontend_class' => 'validate-digits validate-length maximum-length-' . $a['length'],
                'note' => $a['note'],
                'required' => false,
                'global' => Attribute::SCOPE_GLOBAL,
                'visible' => true,
                'user_defined' => true,
                'searchable' => $code === 'si5_gtin',
                'filterable' => false,
                'comparable' => false,
                'visible_on_front' => false,
                'used_in_product_listing' => false,
                'is_used_in_grid' => true,
                'is_filterable_in_grid' => true,
                'unique' => false,
            ]);
        }
        $connection = $this->moduleDataSetup->getConnection();
        $brandId = (int) $eav->getAttributeId($entity, 'manufacturer');
        foreach ($eav->getAllAttributeSetIds($entity) as $setId) {
            $eav->addAttributeGroup($entity, $setId, self::GROUP, 90);
            $groupId = $eav->getAttributeGroupId($entity, $setId, self::GROUP);
            foreach (self::ATTRIBUTES as $code => $a) {
                $eav->addAttributeToGroup($entity, $setId, $groupId, $code, $a['sort']);
            }
            // the brand goes to Magento's own "manufacturer" attribute; a new store does not have it in the
            // attribute sets, so it is added to this group only where it is missing (never moved)
            if ($brandId) {
                $inSet = $connection->fetchOne(
                    $connection->select()->from($this->moduleDataSetup->getTable('eav_entity_attribute'), 'entity_attribute_id')
                        ->where('attribute_set_id = ?', $setId)->where('attribute_id = ?', $brandId)
                );
                if (!$inSet) {
                    $eav->addAttributeToGroup($entity, $setId, $groupId, $brandId, 40);
                }
            }
        }
        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    public function revert(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        /** @var EavSetup $eav */
        $eav = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        foreach (array_keys(self::ATTRIBUTES) as $code) {
            $eav->removeAttribute(Product::ENTITY, $code);
        }
        $this->moduleDataSetup->getConnection()->endSetup();
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
