<?php

namespace console\actions;

class Parser
{
    private $path = 'files/';

    public function __construct()
    {

    }

    /**
     * @return bool
     */
    public function run()
    {
        $aDir = array_slice(scandir(__DIR__ . '/../../' . $this->path . 'in/'), 2);
        if (empty($aDir)) {
            return false;
        }
        if (!is_dir(__DIR__ . '/../../' . $this->path . '1c/')) {
            mkdir(__DIR__ . '/../../' . $this->path . '1c/');
        }
        if (!is_dir(__DIR__ . '/../../' . $this->path . 'out/')) {
            mkdir(__DIR__ . '/../../' . $this->path . 'out/');
        }
        $path = __DIR__ . '/../../' . $this->path . 'in/';
        $pathTo = __DIR__ . '/../../' . $this->path . '1c/';
        $zip = new \ZipArchive;
        $folder = time();
        foreach ($aDir as $archive) {
            $zip->open($path . $archive, \ZipArchive::CREATE);
            $zip->extractTo($pathTo . $folder);
        }

        $aDir = array_slice(scandir($pathTo . $folder), 2);

        $this->parse($pathTo . $folder, $aDir);
    }

    /**
     * @param $path
     * @param $files
     */
    public function parse($path, $files)
    {
        $resultArr = [];
        foreach ($files as $file) {

            $xml = simplexml_load_file($path . '/' . $file);
            if (isset($xml->Классификатор->Группы)) {
                $resultArr['categories'] = $this->parseGroups($xml->Классификатор->Группы->Группа);
            }
            if (isset($xml->Классификатор->ЕдиницыИзмерения)) {
                $resultArr['units'] = $this->parseUnits($xml->Классификатор->ЕдиницыИзмерения->ЕдиницаИзмерения);
            }
            if (isset($xml->Классификатор->Склады)) {
                $resultArr['warehouses'] = $this->parseWarehouses($xml->Классификатор->Склады->Склад);
            }
            if (isset($xml->Каталог->Товары)) {
                $resultArr['products'] = $this->parseProducts($xml->Каталог->Товары->Товар);
            }
            if (isset($xml->ПакетПредложений->Предложения->Предложение->Цены)) {
                $temp = $resultArr['items'];
                $resultArr['items'] = $this->parsePrices($xml->ПакетПредложений->Предложения->Предложение, $temp);
                continue;
            }
            if (isset($xml->ПакетПредложений->Предложения->Предложение->Остатки)) {
                $temp = $resultArr['items'];
                $resultArr['items'] = $this->parseStocks($xml->ПакетПредложений->Предложения->Предложение, $temp);
                continue;
            }
            if (isset($xml->ПакетПредложений->Предложения)) {
                $resultArr['items'] = $this->parseOffers($xml->ПакетПредложений->Предложения->Предложение, $resultArr['products']);
                unset($resultArr['products']);
            }
        }
        $resultArr['items'] = array_values($resultArr['items']);
        $path = str_replace('1c', 'out', $path);
        mkdir($path);
        $f = fopen($path . '/exchange-catalog.json', 'w');
        fwrite($f, json_encode($resultArr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fclose($f);

    }

    /**
     * @param \SimpleXMLElement $groups
     * превращаем группы в категоии
     * @return array
     */
    private function parseGroups(\SimpleXMLElement $groups)
    {
        $returnArr = [];
        foreach ($groups as $group) {
            $returnArr[] = [
                'id' => (string)$group->Ид,
                'name' => (string)$group->Наименование,
            ];
        }
        return $returnArr;
    }

    /**
     * @param \SimpleXMLElement $warehouses
     * @return array
     * склады
     */
    private function parseWarehouses(\SimpleXMLElement $warehouses)
    {
        $returnArr = [];
        foreach ($warehouses as $warehouse) {
            $returnArr[] = [
                'id' => (string)$warehouse->Ид,
                'name' => (string)$warehouse->Наименование,
            ];
        }
        return $returnArr;
    }

    /**
     * @param \SimpleXMLElement $units
     * @return array
     * единицы измерения
     */
    private function parseUnits(\SimpleXMLElement $units)
    {
        $returnArr = [];
        foreach ($units as $unit) {
            $returnArr[] = [
                'id' => (string)$unit->Ид,
                'shortname' => (string)$unit->НаименованиеКраткое,
                'fullname' => (string)$unit->НаименованиеПолное,
                'code' => (int)$unit->Код,
            ];
        }
        return $returnArr;
    }

    /**
     * @param \SimpleXMLElement $products
     * @return array
     * товары
     */
    private function parseProducts(\SimpleXMLElement $products)
    {
        $returnArr = [];
        foreach ($products as $product) {
            if (!$this->validate_EAN13Barcode((string)$product->Штрихкод)) {
                continue;
            }
            $returnArr[(string)$product->Ид] = [
                'barcode' => (string)$product->Штрихкод,
                'name' => (string)$product->Наименование,
                'category_id' => (string)$product->Группы->Ид,
                'description' => (string)$product->Описание,
                'unit_id' => (int)$product->БазоваяЕдиница,
            ];
        }
        return $returnArr;
    }

    /**
     * @param \SimpleXMLElement $offers
     * @param array $products
     * @return array
     *  Предложения
     */
    private function parseOffers(\SimpleXMLElement $offers, $products)
    {
        $returnArr = [];
        foreach ($offers as $offer) {
            if (!isset($products[(string)$offer->Ид])) {
                continue;
            }
            $returnArr[(string)$offer->Ид] = [
                'id' => (string)$offer->Ид,
                'barcodes' => [
                    (string)$offer->Штрихкод
                ],
                'name' => (string)$offer->Наименование,
                'description' => $products[(string)$offer->Ид]['description'],
                'category_id' => $products[(string)$offer->Ид]['category_id'],
                'images_links' => [],
                'unit_id' => $products[(string)$offer->Ид]['unit_id'],
            ];
        }
        return $returnArr;
    }

    /**
     * @param \SimpleXMLElement $offers
     * @param $items
     * @return array
     * цены
     */
    private function parsePrices(\SimpleXMLElement $offers, $items)
    {
        foreach ($offers as $offer) {
            if (!isset($items[(string)$offer->Ид])) {
                continue;
            }
            $items[(string)$offer->Ид]['price'] = (int)$offer->Цены->Цена->ЦенаЗаЕдиницу;
        }
        return $items;
    }

    /**
     * @param \SimpleXMLElement $offers
     * @param $items
     * @return mixed
     * остатки на складах
     */
    private function parseStocks(\SimpleXMLElement $offers, $items)
    {
        foreach ($offers as $offer) {
            if (!isset($items[(string)$offer->Ид])) {
                continue;
            }
            foreach ($offer->Остатки->Остаток as $stock) {
                $items[(string)$offer->Ид]['stocks'][] = [
                    'warehouse_id' => (string)$stock->Склад->Ид,
                    'count' => (int)$stock->Склад->Количество,
                ];
            }
        }
        return $items;
    }

    /**
     * @param $barcode
     * @return bool
     * валидация штрихкода
     */
    private function validate_EAN13Barcode($barcode)
    {
        if (!preg_match("/^[0-9]{13}$/", $barcode)) {
            error_log("неверный штрихкод, штрихкод - " . $barcode . PHP_EOL, 3, __DIR__ . "/error.log");
            return false;
        }

        $digits = $barcode;

        $even_sum = $digits[1] + $digits[3] + $digits[5] +
            $digits[7] + $digits[9] + $digits[11];

        $even_sum_three = $even_sum * 3;

        $odd_sum = $digits[0] + $digits[2] + $digits[4] +
            $digits[6] + $digits[8] + $digits[10];

        $total_sum = $even_sum_three + $odd_sum;

        $next_ten = (ceil($total_sum / 10)) * 10;
        $check_digit = $next_ten - $total_sum;

        if ($check_digit == $digits[12]) {
            return true;
        }

        error_log("сумма не верна, штрихкод - " . $barcode . PHP_EOL, 3, __DIR__ . "/error.log");
        return false;
    }
}