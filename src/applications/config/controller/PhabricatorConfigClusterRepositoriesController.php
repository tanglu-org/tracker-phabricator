<?php

final class PhabricatorConfigClusterRepositoriesController
  extends PhabricatorConfigController {

  public function handleRequest(AphrontRequest $request) {
    $nav = $this->buildSideNavView();
    $nav->selectFilter('cluster/repositories/');

    $title = pht('Repository Servers');

    $crumbs = $this
      ->buildApplicationCrumbs($nav)
      ->addTextCrumb(pht('Repository Servers'));

    $repository_status = $this->buildClusterRepositoryStatus();

    $view = id(new PHUITwoColumnView())
      ->setNavigation($nav)
      ->setMainColumn($repository_status);

    return $this->newPage()
      ->setTitle($title)
      ->setCrumbs($crumbs)
      ->appendChild($view);
  }

  private function buildClusterRepositoryStatus() {
    $viewer = $this->getViewer();

    Javelin::initBehavior('phabricator-tooltips');

    $all_services = id(new AlmanacServiceQuery())
      ->setViewer($viewer)
      ->withServiceTypes(
        array(
          AlmanacClusterRepositoryServiceType::SERVICETYPE,
        ))
      ->needBindings(true)
      ->needProperties(true)
      ->execute();
    $all_services = mpull($all_services, null, 'getPHID');

    $all_repositories = id(new PhabricatorRepositoryQuery())
      ->setViewer($viewer)
      ->withHosted(PhabricatorRepositoryQuery::HOSTED_PHABRICATOR)
      ->withTypes(
        array(
          PhabricatorRepositoryType::REPOSITORY_TYPE_GIT,
        ))
      ->execute();
    $all_repositories = mpull($all_repositories, null, 'getPHID');

    $all_versions = id(new PhabricatorRepositoryWorkingCopyVersion())
      ->loadAll();

    $all_devices = $this->getDevices($all_services, false);
    $all_active_devices = $this->getDevices($all_services, true);

    $leader_versions = $this->getLeaderVersionsByRepository(
      $all_repositories,
      $all_versions,
      $all_active_devices);

    $push_times = $this->loadLeaderPushTimes($leader_versions);

    $repository_groups = mgroup($all_repositories, 'getAlmanacServicePHID');
    $repository_versions = mgroup($all_versions, 'getRepositoryPHID');

    $rows = array();
    foreach ($all_services as $service) {
      $service_phid = $service->getPHID();

      if ($service->getAlmanacPropertyValue('closed')) {
        $status_icon = 'fa-folder';
        $status_tip = pht('Closed');
      } else {
        $status_icon = 'fa-folder-open green';
        $status_tip = pht('Open');
      }

      $status_icon = id(new PHUIIconView())
        ->setIcon($status_icon)
        ->addSigil('has-tooltip')
        ->setMetadata(
          array(
            'tip' => $status_tip,
          ));

      $devices = idx($all_devices, $service_phid, array());
      $active_devices = idx($all_active_devices, $service_phid, array());

      $device_icon = 'fa-server green';

      $device_label = pht(
        '%s Active',
        phutil_count($active_devices));

      $device_status = array(
        id(new PHUIIconView())->setIcon($device_icon),
        ' ',
        $device_label,
      );

      $repositories = idx($repository_groups, $service_phid, array());

      $repository_status = pht(
        '%s',
        phutil_count($repositories));

      $no_leader = array();
      $full_sync = array();
      $partial_sync = array();
      $no_sync = array();
      $lag = array();

      // Threshold in seconds before we start complaining that repositories
      // are not synchronized when there is only one leader.
      $threshold = phutil_units('5 minutes in seconds');

      $messages = array();

      foreach ($repositories as $repository) {
        $repository_phid = $repository->getPHID();

        $leader_version = idx($leader_versions, $repository_phid);
        if ($leader_version === null) {
          $no_leader[] = $repository;
          $messages[] = pht(
            'Repository %s has an ambiguous leader.',
            $viewer->renderHandle($repository_phid)->render());
          continue;
        }

        $versions = idx($repository_versions, $repository_phid, array());

        $leaders = 0;
        foreach ($versions as $version) {
          if ($version->getRepositoryVersion() == $leader_version) {
            $leaders++;
          }
        }

        if ($leaders == count($active_devices)) {
          $full_sync[] = $repository;
        } else {
          $push_epoch = idx($push_times, $repository_phid);
          if ($push_epoch) {
            $duration = (PhabricatorTime::getNow() - $push_epoch);
            $lag[] = $duration;
          } else {
            $duration = null;
          }

          if ($leaders >= 2 || ($duration && ($duration < $threshold))) {
            $partial_sync[] = $repository;
          } else {
            $no_sync[] = $repository;
            if ($push_epoch) {
              $messages[] = pht(
                'Repository %s has unreplicated changes (for %s).',
                $viewer->renderHandle($repository_phid)->render(),
                phutil_format_relative_time($duration));
            } else {
              $messages[] = pht(
                'Repository %s has unreplicated changes.',
                $viewer->renderHandle($repository_phid)->render());
            }
          }

        }
      }

      $with_lag = false;

      if ($no_leader) {
        $replication_icon = 'fa-times red';
        $replication_label = pht('Ambiguous Leader');
      } else if ($no_sync) {
        $replication_icon = 'fa-refresh yellow';
        $replication_label = pht('Unsynchronized');
        $with_lag = true;
      } else if ($partial_sync) {
        $replication_icon = 'fa-refresh green';
        $replication_label = pht('Partial');
        $with_lag = true;
      } else if ($full_sync) {
        $replication_icon = 'fa-check green';
        $replication_label = pht('Synchronized');
      } else {
        $replication_icon = 'fa-times grey';
        $replication_label = pht('No Repositories');
      }

      if ($with_lag && $lag) {
        $lag_status = phutil_format_relative_time(max($lag));
        $lag_status = pht(' (%s)', $lag_status);
      } else {
        $lag_status = null;
      }

      $replication_status = array(
        id(new PHUIIconView())->setIcon($replication_icon),
        ' ',
        $replication_label,
        $lag_status,
      );

      $messages = phutil_implode_html(phutil_tag('br'), $messages);

      $rows[] = array(
        $status_icon,
        $viewer->renderHandle($service->getPHID()),
        $device_status,
        $repository_status,
        $replication_status,
        $messages,
      );
    }


    $table = id(new AphrontTableView($rows))
      ->setNoDataString(
        pht('No repository cluster services are configured.'))
      ->setHeaders(
        array(
          null,
          pht('Service'),
          pht('Devices'),
          pht('Repos'),
          pht('Sync'),
          pht('Messages'),
        ))
      ->setColumnClasses(
        array(
          null,
          'pri',
          null,
          null,
          null,
          'wide',
        ));

    $doc_href = PhabricatorEnv::getDoclink('Cluster: Repositories');

    $header = id(new PHUIHeaderView())
      ->setHeader(pht('Cluster Repository Status'))
      ->addActionLink(
        id(new PHUIButtonView())
          ->setIcon('fa-book')
          ->setHref($doc_href)
          ->setTag('a')
          ->setText(pht('Documentation')));

    return id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->setTable($table);
  }

  private function getDevices(
    array $all_services,
    $only_active) {

    $devices = array();
    foreach ($all_services as $service) {
      $map = array();
      foreach ($service->getBindings() as $binding) {
        if ($only_active && $binding->getIsDisabled()) {
          continue;
        }

        $device = $binding->getDevice();
        $device_phid = $device->getPHID();

        $map[$device_phid] = $device;
      }
      $devices[$service->getPHID()] = $map;
    }

    return $devices;
  }

  private function getLeaderVersionsByRepository(
    array $all_repositories,
    array $all_versions,
    array $active_devices) {

    $version_map = mgroup($all_versions, 'getRepositoryPHID');

    $result = array();
    foreach ($all_repositories as $repository_phid => $repository) {
      $service_phid = $repository->getAlmanacServicePHID();
      if (!$service_phid) {
        continue;
      }

      $devices = idx($active_devices, $service_phid);
      if (!$devices) {
        continue;
      }

      $versions = idx($version_map, $repository_phid, array());
      $versions = mpull($versions, null, 'getDevicePHID');
      $versions = array_select_keys($versions, array_keys($devices));
      if (!$versions) {
        continue;
      }

      $leader = (int)max(mpull($versions, 'getRepositoryVersion'));
      $result[$repository_phid] = $leader;
    }

    return $result;
  }

  private function loadLeaderPushTimes(array $leader_versions) {
    $viewer = $this->getViewer();

    if (!$leader_versions) {
      return array();
    }

    $events = id(new PhabricatorRepositoryPushEventQuery())
      ->setViewer($viewer)
      ->withIDs($leader_versions)
      ->execute();
    $events = mpull($events, null, 'getID');

    $result = array();
    foreach ($leader_versions as $key => $version) {
      $event = idx($events, $version);
      if (!$event) {
        continue;
      }

      $result[$key] = $event->getEpoch();
    }

    return $result;
  }


}
