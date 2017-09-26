<?php declare(strict_types=1);

namespace RssSupportBundle\Service\Collector;

use AppBundle\Collection\FeedCollection;
use AppBundle\Entity\FeedEntry;
use AppBundle\Entity\FeedSource;
use AppBundle\Exception\DuplicatedDataException;
use RssSupportBundle\Factory\Specification\RssSpecificationFactory;
use AppBundle\Service\Collector\CollectorInterface;
use RssSupportBundle\ValueObject\Specification\RssSourceSpecification;
use PicoFeed\Parser\Item;
use PicoFeed\Reader\Reader;
use DateTimeImmutable;

/**
 * RSS Collector
 * =============
 *   Collects articles from RSS sources using an external library
 */
class RssCollector implements CollectorInterface
{
    protected $reader;
    protected $specificationFactory;

    public function __construct(
        Reader $reader,
        RssSpecificationFactory $specificationFactory
    ) {
        $this->reader = $reader;
        $this->specificationFactory = $specificationFactory;
    }

    public function collect(FeedSource $source) : FeedCollection
    {
        $feeds = new FeedCollection();
        $parameters = $this->createParameters($source);
        $resource = $this->reader->download($parameters->getUrl());

        $parser = $this->reader->getParser(
            $resource->getUrl(),
            $resource->getContent(),
            $resource->getEncoding()
        );

        foreach ($parser->execute()->getItems() as $item) {
            try {
                $feeds->add($this->parseFeedItem($item, $source));

            } catch (DuplicatedDataException $exception) {
                // pass
            }
        }

        return $feeds;
    }

    public function isAbleToHandle(FeedSource $source) : bool
    {
        return $source->getCollectorName() === 'rss';
    }

    public static function getCollectorName() : string
    {
        return 'rss';
    }

    public function __toString() : string
    {
        return self::getCollectorName();
    }

    protected function parseFeedItem(
        Item $item,
        FeedSource $feedSource
    ) : FeedEntry {

        return FeedEntry::create([
            'newsId'         => $this->getId($item),
            'feedSource'     => $feedSource,
            'title'          => $item->getTitle(),
            'content'        => $item->getContent(),
            'sourceUrl'      => $item->getUrl(),
            'date'           => DateTimeImmutable::createFromMutable($item->getPublishedDate()),
            'collectionDate' => new DateTimeImmutable('now'),
            'language'       => $item->getLanguage() ? $item->getLanguage() : $feedSource->getDefaultLanguage(),
        ]);
    }

    protected function getId(Item $item) : string
    {
        $url = $item->getUrl();
        $guid = $item->getTag('guid', 'isPermaLink=true');

        if (count($guid) > 0) {
            return hash('sha256', $guid[0]);
        }

        return hash('sha256', $url);
    }

    protected function createParameters(FeedSource $source) : RssSourceSpecification
    {
        return $this->specificationFactory->create($source->getSourceSpecification());
    }
}