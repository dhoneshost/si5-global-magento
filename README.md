# SI5 Global para Magento 2

Módulo (`Si5_GlobalCatalog`) que completa o produto pelo **código de barras (EAN/UPC)**: nome, marca, peso, foto, **NCM e CEST**, a partir do catálogo [SI5 Global](https://global.si5.com.br), com milhões de produtos do varejo brasileiro.

*English summary below.*

## Como funciona

1. Na edição do produto, abra a seção **SI5 Global** e clique em **Buscar no SI5 Global**.
2. O módulo mostra lado a lado o que o produto tem hoje e o que o catálogo sugere.
3. Marque só o que quer trocar e clique em **Aplicar campos marcados**. Nada é gravado sem essa revisão.

O que é gravado em cada campo (sempre no escopo padrão, *All Store Views*):

| Campo | Onde fica no Magento |
|---|---|
| Nome | Nome do produto |
| Marca | Atributo `manufacturer`; a opção é criada se ainda não existir |
| Peso | Peso do produto, na unidade da loja (kg ou lbs) |
| Foto | Nova imagem principal (base, small e thumbnail); as fotos atuais continuam na galeria |
| NCM e CEST | Atributos `si5_ncm` e `si5_cest`, criados pelo módulo na seção SI5 Global |

O módulo também cria o atributo **GTIN / EAN** (`si5_gtin`). O código de barras é lido dele, de atributos `ean`, `gtin`, `barcode` ou `upc` (se a loja tiver) ou do SKU, quando ele é um código válido. Aceita EAN-8, EAN-13, UPC-A e GTIN-14.

NCM e CEST são **sugestões do catálogo**. Confirme com seu contador antes de emitir notas.

## Dados enviados

O módulo consulta `https://global.si5.com.br/api/produtos/{código}` enviando **apenas o código de barras e o seu token**. A foto é baixada só dos servidores de imagem do SI5 Global, sem o token e sem o endereço da loja. Nenhum outro dado (clientes, pedidos, preços) sai da loja.

## Instalação

Pelo Composer, a partir deste repositório:

```bash
composer config repositories.si5-global-magento vcs https://github.com/dhoneshost/si5-global-magento
composer require si5/module-global-catalog:^1.0
bin/magento module:enable Si5_GlobalCatalog
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

Ou copie os arquivos para `app/code/Si5/GlobalCatalog` e rode os quatro comandos `bin/magento` acima.

Depois, crie uma conta e um token em global.si5.com.br (*Minha conta*); o plano gratuito tem uma cota diária de consultas (veja os limites de cada plano em global.si5.com.br). Cole o token em *Lojas › Configuração › SI5 › SI5 Global*.

Permissões (*Sistema › Permissões › Perfis*): "SI5 Global: completar produtos pelo código de barras", dentro de *Catálogo › Produtos*.

Requisitos: Magento 2.4 com PHP 8.1+, cURL e GD.

Testado no Magento Open Source 2.4.6-p15 com PHP 8.1.

## English

Magento 2 module that fills product name, brand, weight, picture and the Brazilian fiscal codes **NCM / CEST** from the barcode (EAN/UPC), using the SI5 Global catalogue. Every change is reviewed in the product form before it is saved. Only the barcode and your API token are sent to the API.

## Licença

[OSL-3.0](LICENSE.txt). © 2026 SI5.
