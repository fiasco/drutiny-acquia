<?php

namespace Drutiny\Acquia;

use Drutiny\DomainList\DomainListInterface;
use Drutiny\Policy;
use Drutiny\Sandbox\Sandbox;
use Drutiny\Target\Target;
use Drutiny\Annotation\Param;

/**
 * @Param(
 *   name = "username",
 *   description = "Username of API Key holder in site factory console.",
 * )
 * @Param(
 *   name = "key",
 *   description = "API Key which can be obtained from site factory account",
 * )
 * @Param(
 *   name = "factory",
 *   description = "URL to the site factory console. Used to derive the API endpoint.",
 * )
 * @Param(
 *   name = "primary-only",
 *   description = "Boolean indicator to include primary sites only.",
 * )
 */
class AcquiaSiteFactoryDomainList implements DomainListInterface {
  protected $username;
  protected $key;
  protected $factory;
  protected $domain_filter;
  protected $primary_only;

  /**
   * The maximum number of sites returned in a single API command to Site
   * Factory.
   *
   * @see https://www.[YOURACSF].acsitefactory.com/api/v1#List-sites
   */
  const SITE_FACTORY_SITES_API_LIMIT = 100;

  public function __construct(array $metadata)
  {
    if (!isset($metadata['username'])) {
      throw new \Exception("Site Factory credentials 'username' parameter is required.");
    }
    if (!isset($metadata['key'])) {
      throw new \Exception("Site Factory credentials 'key' parameter is required.");
    }
    if (!isset($metadata['factory'])) {
      throw new \Exception("Site Factory credentials 'factory' parameter is required.");
    }
    $this->key = $metadata['key'];
    $this->username = $metadata['username'];
    $this->factory = $metadata['factory'];
    $this->primary_only = !empty($metadata['primary-only']);
  }

  /**
   * @return array list of domains.
   */
  public function getDomains(Target $target, callable $filter)
  {
    $client = new \GuzzleHttp\Client([
      'base_uri' => $this->factory . '/api/v1/',
      'auth' => [$this->username, $this->key],
    ]);

    $response = $client->request('GET', 'sites', ['query' => [
      'limit' => self::SITE_FACTORY_SITES_API_LIMIT,
      'page' => 1,
    ]]);

    $json = json_decode($response->getBody(), TRUE);
    $count = $json['count'];
    $sites = $json['sites'];

    // Work out if we need pagination.
    if ($count > 100) {
      for ($i = 2 ; $i <= ceil($count / self::SITE_FACTORY_SITES_API_LIMIT) ; $i++) {
        $response = $client->request('GET', 'sites', ['query' => [
          'limit' => self::SITE_FACTORY_SITES_API_LIMIT,
          'page' => $i,
        ]]);
        $json = json_decode($response->getBody(), TRUE);
        $sites = array_merge($sites, $json['sites']);
      }
    }

    // If provided, filter the sites down by primary sites only.
    $primary_only = $this->primary_only;
    $sites = array_filter($sites, function ($site) use ($primary_only) {
      return !$primary_only || ($primary_only && $site['is_primary']);
    });

    // Build an array of domains to represent each site. Sites with invalid
    // domains will contain a FALSE value to be filtered out later.
    $domains = array_map(function ($site) use ($client, $target, $filter) {
      $nid = $site['site_collection'] ? $site['site_collection'] : $site['id'];
      try {
        $response = $client->request('GET', 'domains/' . $nid);
        $info = json_decode($response->getBody(), TRUE);

        // Custom domains take precedence over defaul "protected" domains.
        if (!empty($info['domains']['custom_domains'])) {
          $domains = $info['domains']['custom_domains'];
        }
        else {
          $domains = $info['domains']['protected_domains'];
        }

        // We only want to return a single domain for a site.
        // So if the filters are available we want to apply them here to
        // before we choose which domain in an array to return.
        $domains = array_filter($domains, $filter);

        // If there are domains that passed the filter,
        // use the first domain as the valid domain to use.
        if (count($domains)) {
          return reset($domains);
        }
        return FALSE;
      }
      catch (\Exception $e) {}
    }, $sites);

    return array_filter($domains);
  }
}

 ?>