<?php

final class PhabricatorFeedApplication extends PhabricatorApplication {

  public function getBaseURI() {
    return '/feed/';
  }

  public function getName() {
    return pht('Feed');
  }

  public function getShortDescription() {
    return pht('Review Recent Activity');
  }

  public function getIcon() {
    return 'fa-newspaper-o';
  }

  public function canUninstall() {
    return false;
  }

  public function getRoutes() {
    return array(
      '/feed/' => array(
        '(?P<id>\d+)/' => 'PhabricatorFeedDetailController',
        '(?:query/(?P<queryKey>[^/]+)/)?' => 'PhabricatorFeedListController',
      ),
    );
  }

}
