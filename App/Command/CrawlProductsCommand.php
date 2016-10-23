<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;

class CrawlProductsCommand extends Command
{
    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var array
     */
    protected $results = [];

    /**
     * @var array
     */
    protected $source;

    /**
     * @var array
     */
    protected $pages = [];

    /**
     * @var array
     */
    protected $errors = [];

    /**
     * @var int
     */
    protected $currentIndex = 1;

    /**
     * Cli configuration
     */
    protected function configure()
    {
        $this
            ->setName('products:crawl')
            ->setDescription('Crawls products for a given language')
            ->setHelp("This command creates a product-list for a given language")

            ->addArgument('source', InputArgument::REQUIRED, 'The path of the source-file relative to the project root')
            ->addArgument('destination', InputArgument::REQUIRED, 'The path of the destination-file relative to the project root')
            ->addArgument('language', InputArgument::REQUIRED, 'The destination language all productnames are already translated into');
    }

    /**
     * Command entry point
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $inputFilePath = $input->getArgument('source');
        $lang = $input->getArgument('language');
        $this->outputFilePath = $input->getArgument('destination');

        $this->output = $output;
        $this->output->writeln('Starting..');

        $url = 'http://www.eucerin.' . $lang . '/products';

        $this->source = $this->readSourceFile(getcwd() . DIRECTORY_SEPARATOR . $inputFilePath);

        foreach ($this->source as &$product) {
            $this->output->writeln('processing: (' . $this->currentIndex++ . '/' . count($this->source) . ') ' . $product['name']);
            $this->crawlInPageOverview($url, $product);
        }

        $this->writeResultFile();

        $this->output->writeln('<error>Errors (' . count($this->errors) . '):</error>');
        $this->output->writeln($this->errors);
    }

    /**
     * Read the source csv and build the source-array from it
     *
     * @param $path
     *
     * @return array
     */
    protected function readSourceFile($path){
        $source = [];

        $file = fopen( $path, 'r');

        // get fields
        if(($line = fgetcsv($file)) !== FALSE){
            $fields = $line;
        }

        // get rows with named keys
        while (($line = fgetcsv($file)) !== FALSE) {
            $source[] = array_combine($fields, $line);
        }

        fclose($file);

        return $source;
    }

    /**
     * Write the processed data to a file
     *
     * @return void
     */
    protected function writeResultFile(){
        $csv = '';

        $fields = array_keys($this->source[0]);

        $csv .= $this->arrayToCsv($fields, ',', '"') . "\n";

        foreach ($this->source as $line) {
            $values = array_values($line);
            $csv .= $this->arrayToCsv($values, ',', '"') . "\n";
        }
        file_put_contents($this->outputFilePath, $csv);
    }

    /**
     * Process a single product
     *
     * @param array $product
     * @param Crawler $baseTeaser
     *
     * @return void
     */
    protected function processProduct(array &$product, Crawler $baseTeaser) {
        // product name
        $baseTeaser->filter('h3')->each(function (Crawler $node, $i) use (&$product){
            $productName = $node->getNode(0)->nodeValue;
            $product['name'] = $productName;
        });

        // product image
        $baseTeaser->filter('img')->each(function (Crawler $node, $i) use (&$product){
            $src = $node->attr('src');
            // remove query string from src since they are used to alter the image
            $src = explode('?', $src);
            $src = reset($src);

            $src = $this->normalizeUri($node->getBaseHref(), $src);
            $product['image'] = $src;
        });

        // product url
        $baseTeaser->filter('a')->each(function (Crawler $node, $i) use (&$product){
            $url = $this->normalizeUri($node->getBaseHref(), $node->attr('href'));
            $product['url'] = $url;

            $this->crawlPageDetails($product);
        });
    }

    /**
     * Look for a given Product in the overview page
     * Process it when found
     * Otherwise add entry to the errors
     *
     * @param string $url
     *
     * @return void
     */
    protected function crawlInPageOverview($url, &$product){

        $page = $this->getPage($url);

        $foundProduct = false;
        // loop through all teasers to find the one we are looking for
        $page->filter('.base-teaser')->each(function (Crawler $baseTeaser, $i) use (&$product, &$foundProduct){
            $baseTeaser->filter('h3')->each(function (Crawler $node, $i) use (&$product, &$foundProduct, $baseTeaser){
                if(trim(strtolower($node->getNode(0)->nodeValue)) == trim(strtolower($product['name']))){
                    $foundProduct = true;

                    $this->processProduct($product, $baseTeaser);
                }
            });
        });

        if(!$foundProduct) {
            $this->output->writeln('<error>failed    :  ' . $product['name'] . '</error>');
            $this->errors[] = $product['name'];
        } else {
            $this->output->writeln('<info>done      :  ' . $product['name'] . '</info>');
        }
    }

    /**
     * Find infos available via the detailspage of a product
     *
     * @param array $product
     *
     * @return void
     */
    protected function crawlPageDetails(array &$product) {
        $page = $this->getPage($product['url']);

        $page->filter('.product-head')->each(function (Crawler $node, $i) use (&$product){
            //product description
            $node->filter('.subtitle')->each(function (Crawler $node, $i) use (&$product){
                $productDescription = $node->getNode(0)->nodeValue;
                $product['description'] = $productDescription;
            });
        });
    }

    /**
     * Get a page by a passed url
     *
     * @param $url
     *
     * @return Crawler
     */
    protected function getPage($url){
        if(isset($this->pages[$url])){
            return $this->pages[$url];
        }

        $client = new Client();

        try {
            $crawler = $client->request('GET', $url);
            $this->pages[$url] = $crawler;
            return $crawler;
        } catch (\Exception $e) {
            die($e->getMessage());
        }
    }

    /**
     * Build an absolute url from a given baseHref and uri
     *
     * @param string $baseHref
     * @param string $uri
     *
     * @return string $uri
     */
    protected function normalizeUri($baseHref, $uri)
    {
        $base = parse_url($baseHref);

        if(strpos($uri, '/') === 0) {
            // handle url that refers absolutely from host
            $url = $base['scheme'] . '://' . $base['host'] . $uri;
        } else {
            throw new \Exception('could not normalize passed uri: `' . $uri . '`');
        };

        return $url;
    }

    /**
     * Taken from: http://stackoverflow.com/questions/3933668/convert-array-into-csv
     * Formats a line (passed as a fields  array) as CSV and returns the CSV as a string.
     * Adapted from http://us3.php.net/manual/en/function.fputcsv.php#87120
     */
    function arrayToCsv( array &$fields, $delimiter = ';', $enclosure = '"', $encloseAll = false, $nullToMysqlNull = false ) {
        $delimiter_esc = preg_quote($delimiter, '/');
        $enclosure_esc = preg_quote($enclosure, '/');

        $output = array();
        foreach ( $fields as $field ) {
            if ($field === null && $nullToMysqlNull) {
                $output[] = 'NULL';
                continue;
            }

            // Enclose fields containing $delimiter, $enclosure or whitespace
            if ( $encloseAll || preg_match( "/(?:${delimiter_esc}|${enclosure_esc}|\s)/", $field ) ) {
                $output[] = $enclosure . str_replace($enclosure, $enclosure . $enclosure, $field) . $enclosure;
            }
            else {
                $output[] = $field;
            }
        }

        return implode( $delimiter, $output );
    }
}