<?php
/**
 * @license OSL-3.0
 */
declare(strict_types=1);

namespace Si5\GlobalCatalog\Model;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Api\AttributeOptionManagementInterface;
use Magento\Eav\Api\Data\AttributeOptionInterfaceFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Store\Model\Store;

/**
 * Reads the product as it is today and writes only the fields the admin user ticked.
 */
class Applier
{
    public const FIELDS = ['nome', 'marca', 'peso', 'imagem', 'ncm', 'cest'];
    private const BRAND = 'manufacturer';
    private const BARCODE_ATTRIBUTES = ['si5_gtin', 'ean', 'gtin', 'barcode', 'upc'];

    private ProductRepositoryInterface $products;
    private ScopeConfigInterface $config;
    private Client $client;
    private Filesystem $filesystem;
    private AttributeOptionManagementInterface $options;
    private AttributeOptionInterfaceFactory $optionFactory;

    public function __construct(
        ProductRepositoryInterface $products,
        ScopeConfigInterface $config,
        Client $client,
        Filesystem $filesystem,
        AttributeOptionManagementInterface $options,
        AttributeOptionInterfaceFactory $optionFactory
    ) {
        $this->products = $products;
        $this->config = $config;
        $this->client = $client;
        $this->filesystem = $filesystem;
        $this->options = $options;
        $this->optionFactory = $optionFactory;
    }

    public function isEnabled(): bool
    {
        return $this->config->isSetFlag('si5global/general/enabled');
    }

    public function hasToken(): bool
    {
        return $this->token() !== '';
    }

    /** config.xml declares the Encrypted backend, so ScopeConfig already returns the decrypted value. */
    private function token(): string
    {
        return trim((string) $this->config->getValue('si5global/general/token'));
    }

    /** @return Product */
    private function load(int $id): ProductInterface
    {
        if ($id <= 0) {
            throw new LocalizedException(__('Save the product first.'));
        }

        return $this->products->getById($id, true, Store::DEFAULT_STORE_ID, true);
    }

    public function barcode(Product $product): ?string
    {
        foreach (self::BARCODE_ATTRIBUTES as $code) {
            $value = $product->getData($code);
            if (is_scalar($value) && ($gtin = Client::gtin($value)) !== null) {
                return $gtin;
            }
        }

        return Client::gtin($product->getSku());
    }

    /** @return array{atual:array<string,string>,sugerido:array} */
    public function lookup(int $id): array
    {
        $product = $this->load($id);
        $gtin = $this->barcode($product);
        if ($gtin === null) {
            throw new LocalizedException(__('Fill a valid barcode in "GTIN / EAN" (SI5 Global section) or in the SKU and save the product.'));
        }
        $s = $this->client->lookup($this->token(), $gtin);
        $s['peso'] = '';
        if ($s['peso_gramas']) {
            [$value, $unit] = $this->weightInStoreUnit((float) $s['peso_gramas']);
            $s['peso'] = $this->number($value) . ' ' . $unit;
        }
        $image = (string) $product->getImage();
        $current = [
            'nome' => (string) $product->getName(),
            'marca' => $this->hasBrand($product) ? (string) $product->getAttributeText(self::BRAND) : (string) __('(attribute "manufacturer" is not in this attribute set)'),
            'peso' => (float) $product->getWeight() > 0 ? $this->number((float) $product->getWeight()) : '',
            'imagem' => $image !== '' && $image !== 'no_selection' ? (string) __('has a picture (it stays in the gallery)') : '',
            'ncm' => (string) $product->getData('si5_ncm'),
            'cest' => (string) $product->getData('si5_cest'),
        ];

        return ['atual' => $current, 'sugerido' => $s];
    }

    /**
     * @param string[] $fields
     * @return string[] names of the changed fields
     */
    public function apply(int $id, array $fields, array $s): array
    {
        $fields = array_values(array_intersect(self::FIELDS, $fields));
        if (!$fields) {
            throw new LocalizedException(__('Tick at least one field to apply.'));
        }
        $product = $this->load($id);
        $changed = [];

        if (in_array('nome', $fields, true)) {
            $name = trim(mb_substr(strip_tags((string) ($s['nome'] ?? '')), 0, 255));
            if ($name === '') {
                throw new LocalizedException(__('Invalid suggested name.'));
            }
            $product->setName($name);
            $changed[] = (string) __('name');
        }

        if (in_array('marca', $fields, true) && trim((string) ($s['marca'] ?? '')) !== '') {
            if (!$this->hasBrand($product)) {
                throw new LocalizedException(__('Add the "manufacturer" attribute to this attribute set to fill the brand.'));
            }
            $product->setData(self::BRAND, $this->brandOption($product, trim(mb_substr(strip_tags((string) $s['marca']), 0, 255))));
            $changed[] = (string) __('brand');
        }

        if (in_array('peso', $fields, true) && is_numeric($s['peso_gramas'] ?? null) && (float) $s['peso_gramas'] > 0) {
            [$value] = $this->weightInStoreUnit((float) $s['peso_gramas']);
            $product->setWeight($value);
            $product->setData('product_has_weight', 1);
            $changed[] = (string) __('weight');
        }

        if (in_array('imagem', $fields, true) && !empty($s['imagem_url'])) {
            $this->addImage($product, (string) $s['imagem_url'], (string) ($s['gtin'] ?? $id));
            $changed[] = (string) __('picture');
        }

        foreach (['ncm' => 8, 'cest' => 7] as $field => $length) {
            $value = (string) preg_replace('/\D/', '', (string) ($s[$field] ?? ''));
            if (in_array($field, $fields, true) && strlen($value) === $length) {
                $product->setData('si5_' . $field, $value);
                $changed[] = strtoupper($field);
            }
        }

        if ($changed) {
            $this->products->save($product);
        }

        return $changed;
    }

    private function hasBrand(Product $product): bool
    {
        return isset($product->getTypeInstance()->getSetAttributes($product)[self::BRAND]);
    }

    /** Option id of the brand in the "manufacturer" attribute; created when it does not exist yet. */
    private function brandOption(Product $product, string $label): string
    {
        $attribute = $product->getResource()->getAttribute(self::BRAND);
        foreach ($attribute->getSource()->getAllOptions(false) as $option) {
            if (mb_strtolower(trim((string) $option['label'])) === mb_strtolower($label)) {
                return (string) $option['value'];
            }
        }
        $option = $this->optionFactory->create();
        $option->setLabel($label);
        $id = (string) $this->options->add(Product::ENTITY, self::BRAND, $option);
        if (ctype_digit($id)) {
            return $id;
        }
        foreach ($this->options->getItems(Product::ENTITY, self::BRAND) as $o) {
            if (mb_strtolower(trim((string) $o->getLabel())) === mb_strtolower($label)) {
                return (string) $o->getValue();
            }
        }
        throw new LocalizedException(__('Could not create the brand "%1".', $label));
    }

    /** Picture as JPEG (any PHP GD reads it), new main picture; the current pictures stay in the gallery. */
    private function addImage(Product $product, string $url, string $gtin): void
    {
        [$bytes] = $this->client->downloadImage($url);
        $img = @imagecreatefromstring($bytes);
        if (!$img) {
            throw new LocalizedException(__('PHP on this server cannot read the picture (GD without WebP support?).'));
        }
        $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $relative = 'tmp/si5global/' . preg_replace('/\D/', '', $gtin) . '-' . bin2hex(random_bytes(4)) . '.jpg';
        $media->create('tmp/si5global');
        ob_start();
        imagejpeg($img, null, 92);
        $media->writeFile($relative, (string) ob_get_clean());
        imagedestroy($img);
        $product->addImageToMediaGallery($media->getAbsolutePath($relative), ['image', 'small_image', 'thumbnail'], true, false);
    }

    /** @return array{0:float,1:string} */
    private function weightInStoreUnit(float $grams): array
    {
        $unit = (string) $this->config->getValue('general/locale/weight_unit');
        if ($unit === 'lbs') {
            return [round($grams / 453.59237, 4), 'lbs'];
        }

        return [round($grams / 1000, 4), 'kg'];
    }

    private function number(float $v): string
    {
        return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
    }
}
