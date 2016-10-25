<?php

namespace Drupal\elasticsearch_helper_example\Plugin\ElasticsearchIndex;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\elasticsearch_helper\Annotation\ElasticsearchIndex;
use Drupal\elasticsearch_helper\Plugin\ElasticsearchIndexBase;
use Drupal\node\Entity\Node;
use Elasticsearch\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Serializer\Serializer;

/**
 * @ElasticsearchIndex(
 *   id = "multilingual_content_index",
 *   label = @Translation("Multilingual Content Index"),
 *   indexName = "multilingual-{langcode}",
 *   typeName = "node",
 *   entityType = "node"
 * )
 */
class MultilingualContentIndex extends ElasticsearchIndexBase {

  /**
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $language_manager;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, Client $client, Serializer $serializer, LoggerInterface $logger, LanguageManagerInterface $languageManager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $client, $serializer, $logger);

    $this->language_manager = $languageManager;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('elasticsearch_helper.elasticsearch_client'),
      $container->get('serializer'),
      $container->get('logger.factory')->get('elasticsearch_helper'),
      $container->get('language_manager')
    );
  }

  /**
   * @inheritdoc
   */
  public function serialize($source, $context = array()) {
    /** @var Node $source */

    $data = parent::serialize($source, $context);

    // Add the language code to be used as a token.
    $data['langcode'] = $source->language()->getId();

    return $data;
  }

  /**
   * @inheritdoc
   */
  public function index($source) {
    /** @var Node $source */
    foreach ($source->getTranslationLanguages() as $langcode => $language) {
      if ($source->hasTranslation($langcode)) {
        parent::index($source->getTranslation($langcode));
      }
    }
  }

  /**
   * @inheritdoc
   */
  public function delete($source) {
    /** @var Node $source */
   foreach ($source->getTranslationLanguages() as $langcode => $language) {
      if ($source->hasTranslation($langcode)) {
        parent::delete($source->getTranslation($langcode));
      }
    }
  }

  /**
   * @inheritdoc
   */
  public function setup() {
    // Create one index per language, so that we can have different analyzers.
    foreach ($this->language_manager->getLanguages() as $langcode => $language) {

      if (!$this->client->indices()->exists(['index' => 'multilingual-' . $langcode])) {
        $this->client->indices()->create([
          'index' => 'multilingual-' . $langcode,
          'body' => [
            'number_of_shards' => 1,
            'number_of_replicas' => 0,
          ]
        ]);

        $analyzer = $this->getLanguageAnalyzer($langcode);

        $mapping = [
          'index' => 'multilingual-' . $langcode,
          'type' => 'node',
          'body' => [
            'properties' => [
              'title' => [
                'type' => 'string',
                'analyzer' => $analyzer,
              ],
            ],
          ],
        ];

        $this->client->indices()->putMapping($mapping);
      }
    }
  }

  /**
   * Get the name of the language analyzer to be used for a given language code.
   *
   * @param $langcode
   * @return mixed|string
   */
  protected function getLanguageAnalyzer($langcode) {
    $language_analyzers = [
      // Use one of the built-in language analysers. The complete list is
      // available here https://www.elastic.co/guide/en/elasticsearch/reference/current/analysis-lang-analyzer.html
      'en' => 'english',
      'ee' => 'estonian',
      'nl' => 'dutch',
      'fi' => 'finnish',
      'fr' => 'french',
      'de' => 'german',
      'lv' => 'latvian',
      'se' => 'swedish',

      // Chinese, install the analysis-smartcn elasticsearch plugin.
      'zh-hans' => 'smartcn',

      // Japanese, install the analysis-kuromoji elasticsearch plugin.
      'ja' => 'kuromoji',
    ];

    if (isset($language_analyzers[$langcode])) {
      return $language_analyzers[$langcode];
    }
    else {
      return 'standard';
    }
  }
}