<?php

final class PhabricatorProjectTransactionEditor
  extends PhabricatorApplicationTransactionEditor {

  public function getEditorApplicationClass() {
    return 'PhabricatorProjectApplication';
  }

  public function getEditorObjectsDescription() {
    return pht('Projects');
  }

  public function getTransactionTypes() {
    $types = parent::getTransactionTypes();

    $types[] = PhabricatorTransactions::TYPE_EDGE;
    $types[] = PhabricatorTransactions::TYPE_VIEW_POLICY;
    $types[] = PhabricatorTransactions::TYPE_EDIT_POLICY;
    $types[] = PhabricatorTransactions::TYPE_JOIN_POLICY;

    $types[] = PhabricatorProjectTransaction::TYPE_NAME;
    $types[] = PhabricatorProjectTransaction::TYPE_SLUGS;
    $types[] = PhabricatorProjectTransaction::TYPE_STATUS;
    $types[] = PhabricatorProjectTransaction::TYPE_IMAGE;
    $types[] = PhabricatorProjectTransaction::TYPE_ICON;
    $types[] = PhabricatorProjectTransaction::TYPE_COLOR;
    $types[] = PhabricatorProjectTransaction::TYPE_LOCKED;
    $types[] = PhabricatorProjectTransaction::TYPE_PARENT;
    $types[] = PhabricatorProjectTransaction::TYPE_MILESTONE;

    return $types;
  }

  protected function getCustomTransactionOldValue(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case PhabricatorProjectTransaction::TYPE_NAME:
        return $object->getName();
      case PhabricatorProjectTransaction::TYPE_SLUGS:
        $slugs = $object->getSlugs();
        $slugs = mpull($slugs, 'getSlug', 'getSlug');
        unset($slugs[$object->getPrimarySlug()]);
        return array_keys($slugs);
      case PhabricatorProjectTransaction::TYPE_STATUS:
        return $object->getStatus();
      case PhabricatorProjectTransaction::TYPE_IMAGE:
        return $object->getProfileImagePHID();
      case PhabricatorProjectTransaction::TYPE_ICON:
        return $object->getIcon();
      case PhabricatorProjectTransaction::TYPE_COLOR:
        return $object->getColor();
      case PhabricatorProjectTransaction::TYPE_LOCKED:
        return (int)$object->getIsMembershipLocked();
      case PhabricatorProjectTransaction::TYPE_PARENT:
      case PhabricatorProjectTransaction::TYPE_MILESTONE:
        return null;
    }

    return parent::getCustomTransactionOldValue($object, $xaction);
  }

  protected function getCustomTransactionNewValue(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case PhabricatorProjectTransaction::TYPE_NAME:
      case PhabricatorProjectTransaction::TYPE_STATUS:
      case PhabricatorProjectTransaction::TYPE_IMAGE:
      case PhabricatorProjectTransaction::TYPE_ICON:
      case PhabricatorProjectTransaction::TYPE_COLOR:
      case PhabricatorProjectTransaction::TYPE_LOCKED:
      case PhabricatorProjectTransaction::TYPE_PARENT:
        return $xaction->getNewValue();
      case PhabricatorProjectTransaction::TYPE_SLUGS:
        return $this->normalizeSlugs($xaction->getNewValue());
      case PhabricatorProjectTransaction::TYPE_MILESTONE:
        $current = queryfx_one(
          $object->establishConnection('w'),
          'SELECT MAX(milestoneNumber) n
            FROM %T
            WHERE parentProjectPHID = %s',
          $object->getTableName(),
          $object->getParentProject()->getPHID());
        if (!$current) {
          $number = 1;
        } else {
          $number = (int)$current['n'] + 1;
        }
        return $number;
    }

    return parent::getCustomTransactionNewValue($object, $xaction);
  }

  protected function applyCustomInternalTransaction(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case PhabricatorProjectTransaction::TYPE_NAME:
        $name = $xaction->getNewValue();
        $object->setName($name);
        $object->setPrimarySlug(PhabricatorSlug::normalizeProjectSlug($name));
        return;
      case PhabricatorProjectTransaction::TYPE_SLUGS:
        return;
      case PhabricatorProjectTransaction::TYPE_STATUS:
        $object->setStatus($xaction->getNewValue());
        return;
      case PhabricatorProjectTransaction::TYPE_IMAGE:
        $object->setProfileImagePHID($xaction->getNewValue());
        return;
      case PhabricatorProjectTransaction::TYPE_ICON:
        $object->setIcon($xaction->getNewValue());
        return;
      case PhabricatorProjectTransaction::TYPE_COLOR:
        $object->setColor($xaction->getNewValue());
        return;
      case PhabricatorProjectTransaction::TYPE_LOCKED:
        $object->setIsMembershipLocked($xaction->getNewValue());
        return;
      case PhabricatorProjectTransaction::TYPE_PARENT:
        $object->setParentProjectPHID($xaction->getNewValue());
        return;
      case PhabricatorProjectTransaction::TYPE_MILESTONE:
        $object->setMilestoneNumber($xaction->getNewValue());
        return;
    }

    return parent::applyCustomInternalTransaction($object, $xaction);
  }

  protected function applyCustomExternalTransaction(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    $old = $xaction->getOldValue();
    $new = $xaction->getNewValue();

    switch ($xaction->getTransactionType()) {
      case PhabricatorProjectTransaction::TYPE_NAME:
        // First, add the old name as a secondary slug; this is helpful
        // for renames and generally a good thing to do.
        if ($old !== null) {
          $this->addSlug($object, $old, false);
        }
        $this->addSlug($object, $new, false);

        return;
      case PhabricatorProjectTransaction::TYPE_SLUGS:
        $old = $xaction->getOldValue();
        $new = $xaction->getNewValue();
        $add = array_diff($new, $old);
        $rem = array_diff($old, $new);

        foreach ($add as $slug) {
          $this->addSlug($object, $slug, true);
        }

        $this->removeSlugs($object, $rem);
        return;
      case PhabricatorProjectTransaction::TYPE_STATUS:
      case PhabricatorProjectTransaction::TYPE_IMAGE:
      case PhabricatorProjectTransaction::TYPE_ICON:
      case PhabricatorProjectTransaction::TYPE_COLOR:
      case PhabricatorProjectTransaction::TYPE_LOCKED:
      case PhabricatorProjectTransaction::TYPE_PARENT:
      case PhabricatorProjectTransaction::TYPE_MILESTONE:
        return;
     }

    return parent::applyCustomExternalTransaction($object, $xaction);
  }

  protected function applyBuiltinExternalTransaction(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case PhabricatorTransactions::TYPE_EDGE:
        $edge_type = $xaction->getMetadataValue('edge:type');
        switch ($edge_type) {
          case PhabricatorProjectProjectHasMemberEdgeType::EDGECONST:
          case PhabricatorObjectHasWatcherEdgeType::EDGECONST:
            $old = $xaction->getOldValue();
            $new = $xaction->getNewValue();

            // When adding members or watchers, we add subscriptions.
            $add = array_keys(array_diff_key($new, $old));

            // When removing members, we remove their subscription too.
            // When unwatching, we leave subscriptions, since it's fine to be
            // subscribed to a project but not be a member of it.
            $edge_const = PhabricatorProjectProjectHasMemberEdgeType::EDGECONST;
            if ($edge_type == $edge_const) {
              $rem = array_keys(array_diff_key($old, $new));
            } else {
              $rem = array();
            }

            // NOTE: The subscribe is "explicit" because there's no implicit
            // unsubscribe, so Join -> Leave -> Join doesn't resubscribe you
            // if we use an implicit subscribe, even though you never willfully
            // unsubscribed. Not sure if adding implicit unsubscribe (which
            // would not write the unsubscribe row) is justified to deal with
            // this, which is a fairly weird edge case and pretty arguable both
            // ways.

            // Subscriptions caused by watches should also clearly be explicit,
            // and that case is unambiguous.

            id(new PhabricatorSubscriptionsEditor())
              ->setActor($this->requireActor())
              ->setObject($object)
              ->subscribeExplicit($add)
              ->unsubscribe($rem)
              ->save();

            if ($rem) {
              // When removing members, also remove any watches on the project.
              $edge_editor = new PhabricatorEdgeEditor();
              foreach ($rem as $rem_phid) {
                $edge_editor->removeEdge(
                  $object->getPHID(),
                  PhabricatorObjectHasWatcherEdgeType::EDGECONST,
                  $rem_phid);
              }
              $edge_editor->save();
            }
            break;
        }
        break;
    }

    return parent::applyBuiltinExternalTransaction($object, $xaction);
  }

  protected function validateTransaction(
    PhabricatorLiskDAO $object,
    $type,
    array $xactions) {

    $errors = parent::validateTransaction($object, $type, $xactions);

    switch ($type) {
      case PhabricatorProjectTransaction::TYPE_NAME:
        $missing = $this->validateIsEmptyTextField(
          $object->getName(),
          $xactions);

        if ($missing) {
          $error = new PhabricatorApplicationTransactionValidationError(
            $type,
            pht('Required'),
            pht('Project name is required.'),
            nonempty(last($xactions), null));

          $error->setIsMissingFieldError(true);
          $errors[] = $error;
        }

        if (!$xactions) {
          break;
        }

        $name = last($xactions)->getNewValue();

        if (!PhabricatorSlug::isValidProjectSlug($name)) {
          $errors[] = new PhabricatorApplicationTransactionValidationError(
            $type,
            pht('Invalid'),
            pht(
              'Project names must contain at least one letter or number.'),
            last($xactions));
          break;
        }

        $name_used_already = id(new PhabricatorProjectQuery())
          ->setViewer($this->getActor())
          ->withNames(array($name))
          ->executeOne();
        if ($name_used_already &&
           ($name_used_already->getPHID() != $object->getPHID())) {
          $error = new PhabricatorApplicationTransactionValidationError(
            $type,
            pht('Duplicate'),
            pht('Project name is already used.'),
            nonempty(last($xactions), null));
          $errors[] = $error;
        }

        $slug = PhabricatorSlug::normalizeProjectSlug($name);

        $slug_used_already = id(new PhabricatorProjectSlug())
          ->loadOneWhere('slug = %s', $slug);
        if ($slug_used_already &&
            $slug_used_already->getProjectPHID() != $object->getPHID()) {
          $error = new PhabricatorApplicationTransactionValidationError(
            $type,
            pht('Duplicate'),
            pht('Project name can not be used due to hashtag collision.'),
            nonempty(last($xactions), null));
          $errors[] = $error;
        }
        break;
      case PhabricatorProjectTransaction::TYPE_SLUGS:
        if (!$xactions) {
          break;
        }

        $slug_xaction = last($xactions);

        $new = $slug_xaction->getNewValue();

        $invalid = array();
        foreach ($new as $slug) {
          if (!PhabricatorSlug::isValidProjectSlug($slug)) {
            $invalid[] = $slug;
          }
        }

        if ($invalid) {
          $errors[] = new PhabricatorApplicationTransactionValidationError(
            $type,
            pht('Invalid'),
            pht(
              'Hashtags must contain at least one letter or number. %s '.
              'project hashtag(s) are invalid: %s.',
              phutil_count($invalid),
              implode(', ', $invalid)),
            $slug_xaction);
          break;
        }

        $new = $this->normalizeSlugs($new);

        if ($new) {
          $slugs_used_already = id(new PhabricatorProjectSlug())
            ->loadAllWhere('slug IN (%Ls)', $new);
        } else {
          // The project doesn't have any extra slugs.
          $slugs_used_already = array();
        }

        $slugs_used_already = mgroup($slugs_used_already, 'getProjectPHID');
        foreach ($slugs_used_already as $project_phid => $used_slugs) {
          if ($project_phid == $object->getPHID()) {
            continue;
          }

          $used_slug_strs = mpull($used_slugs, 'getSlug');

          $error = new PhabricatorApplicationTransactionValidationError(
            $type,
            pht('Invalid'),
            pht(
              '%s project hashtag(s) are already used by other projects: %s.',
              phutil_count($used_slug_strs),
              implode(', ', $used_slug_strs)),
            $slug_xaction);
          $errors[] = $error;
        }

        break;
      case PhabricatorProjectTransaction::TYPE_PARENT:
        if (!$xactions) {
          break;
        }

        $xaction = last($xactions);

        if (!$this->getIsNewObject()) {
          $errors[] = new PhabricatorApplicationTransactionValidationError(
            $type,
            pht('Invalid'),
            pht(
              'You can only set a parent project when creating a project '.
              'for the first time.'),
            $xaction);
          break;
        }

        $parent_phid = $xaction->getNewValue();

        $projects = id(new PhabricatorProjectQuery())
          ->setViewer($this->requireActor())
          ->withPHIDs(array($parent_phid))
          ->requireCapabilities(
            array(
              PhabricatorPolicyCapability::CAN_VIEW,
              PhabricatorPolicyCapability::CAN_EDIT,
            ))
          ->execute();
        if (!$projects) {
          $errors[] = new PhabricatorApplicationTransactionValidationError(
            $type,
            pht('Invalid'),
            pht(
              'Parent project PHID ("%s") must be the PHID of a valid, '.
              'visible project which you have permission to edit.',
              $parent_phid),
            $xaction);
          break;
        }

        $project = head($projects);

        if ($project->isMilestone()) {
          $errors[] = new PhabricatorApplicationTransactionValidationError(
            $type,
            pht('Invalid'),
            pht(
              'Parent project PHID ("%s") must not be a milestone. '.
              'Milestones may not have subprojects.',
              $parent_phid),
            $xaction);
          break;
        }

        $limit = PhabricatorProject::getProjectDepthLimit();
        if ($project->getProjectDepth() >= ($limit - 1)) {
          $errors[] = new PhabricatorApplicationTransactionValidationError(
            $type,
            pht('Invalid'),
            pht(
              'You can not create a subproject under this parent because '.
              'it would nest projects too deeply. The maximum nesting '.
              'depth of projects is %s.',
              new PhutilNumber($limit)),
            $xaction);
          break;
        }

        $object->attachParentProject($project);
        break;
    }

    return $errors;
  }


  protected function requireCapabilities(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case PhabricatorProjectTransaction::TYPE_NAME:
      case PhabricatorProjectTransaction::TYPE_STATUS:
      case PhabricatorProjectTransaction::TYPE_IMAGE:
      case PhabricatorProjectTransaction::TYPE_ICON:
      case PhabricatorProjectTransaction::TYPE_COLOR:
        PhabricatorPolicyFilter::requireCapability(
          $this->requireActor(),
          $object,
          PhabricatorPolicyCapability::CAN_EDIT);
        return;
      case PhabricatorProjectTransaction::TYPE_LOCKED:
        PhabricatorPolicyFilter::requireCapability(
          $this->requireActor(),
          newv($this->getEditorApplicationClass(), array()),
          ProjectCanLockProjectsCapability::CAPABILITY);
        return;
      case PhabricatorTransactions::TYPE_EDGE:
        switch ($xaction->getMetadataValue('edge:type')) {
          case PhabricatorProjectProjectHasMemberEdgeType::EDGECONST:
            $old = $xaction->getOldValue();
            $new = $xaction->getNewValue();

            $add = array_keys(array_diff_key($new, $old));
            $rem = array_keys(array_diff_key($old, $new));

            $actor_phid = $this->requireActor()->getPHID();

            $is_join = (($add === array($actor_phid)) && !$rem);
            $is_leave = (($rem === array($actor_phid)) && !$add);

            if ($is_join) {
              // You need CAN_JOIN to join a project.
              PhabricatorPolicyFilter::requireCapability(
                $this->requireActor(),
                $object,
                PhabricatorPolicyCapability::CAN_JOIN);
            } else if ($is_leave) {
              // You usually don't need any capabilities to leave a project.
              if ($object->getIsMembershipLocked()) {
                // you must be able to edit though to leave locked projects
                PhabricatorPolicyFilter::requireCapability(
                  $this->requireActor(),
                  $object,
                  PhabricatorPolicyCapability::CAN_EDIT);
              }
            } else {
              // You need CAN_EDIT to change members other than yourself.
              PhabricatorPolicyFilter::requireCapability(
                $this->requireActor(),
                $object,
                PhabricatorPolicyCapability::CAN_EDIT);
            }
            return;
        }
        break;
    }

    return parent::requireCapabilities($object, $xaction);
  }

  protected function willPublish(PhabricatorLiskDAO $object, array $xactions) {
    $member_phids = PhabricatorEdgeQuery::loadDestinationPHIDs(
      $object->getPHID(),
      PhabricatorProjectProjectHasMemberEdgeType::EDGECONST);
    $object->attachMemberPHIDs($member_phids);

    return $object;
  }

  protected function shouldSendMail(
    PhabricatorLiskDAO $object,
    array $xactions) {
    return true;
  }

  protected function getMailSubjectPrefix() {
    return pht('[Project]');
  }

  protected function getMailTo(PhabricatorLiskDAO $object) {
    return $object->getMemberPHIDs();
  }

  protected function getMailCC(PhabricatorLiskDAO $object) {
    $all = parent::getMailCC($object);
    return array_diff($all, $object->getMemberPHIDs());
  }

  public function getMailTagsMap() {
    return array(
      PhabricatorProjectTransaction::MAILTAG_METADATA =>
        pht('Project name, hashtags, icon, image, or color changes.'),
      PhabricatorProjectTransaction::MAILTAG_MEMBERS =>
        pht('Project membership changes.'),
      PhabricatorProjectTransaction::MAILTAG_WATCHERS =>
        pht('Project watcher list changes.'),
      PhabricatorProjectTransaction::MAILTAG_SUBSCRIBERS =>
        pht('Project subscribers change.'),
      PhabricatorProjectTransaction::MAILTAG_OTHER =>
        pht('Other project activity not listed above occurs.'),
    );
  }

  protected function buildReplyHandler(PhabricatorLiskDAO $object) {
    return id(new ProjectReplyHandler())
      ->setMailReceiver($object);
  }

  protected function buildMailTemplate(PhabricatorLiskDAO $object) {
    $id = $object->getID();
    $name = $object->getName();

    return id(new PhabricatorMetaMTAMail())
      ->setSubject("{$name}")
      ->addHeader('Thread-Topic', "Project {$id}");
  }

  protected function buildMailBody(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $body = parent::buildMailBody($object, $xactions);

    $uri = '/project/profile/'.$object->getID().'/';
    $body->addLinkSection(
      pht('PROJECT DETAIL'),
      PhabricatorEnv::getProductionURI($uri));

    return $body;
  }

  protected function shouldPublishFeedStory(
    PhabricatorLiskDAO $object,
    array $xactions) {
    return true;
  }

  protected function supportsSearch() {
    return true;
  }

  protected function extractFilePHIDsFromCustomTransaction(
    PhabricatorLiskDAO $object,
    PhabricatorApplicationTransaction $xaction) {

    switch ($xaction->getTransactionType()) {
      case PhabricatorProjectTransaction::TYPE_IMAGE:
        $new = $xaction->getNewValue();
        if ($new) {
          return array($new);
        }
        break;
    }

    return parent::extractFilePHIDsFromCustomTransaction($object, $xaction);
  }

  protected function applyFinalEffects(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $materialize = false;
    foreach ($xactions as $xaction) {
      switch ($xaction->getTransactionType()) {
        case PhabricatorTransactions::TYPE_EDGE:
          switch ($xaction->getMetadataValue('edge:type')) {
            case PhabricatorProjectProjectHasMemberEdgeType::EDGECONST:
              $materialize = true;
              break;
          }
          break;
        case PhabricatorProjectTransaction::TYPE_PARENT:
          $materialize = true;
          break;
      }
    }

    if ($materialize) {
      id(new PhabricatorProjectsMembershipIndexEngineExtension())
        ->rematerialize($object);
    }

    return parent::applyFinalEffects($object, $xactions);
  }

  private function addSlug(PhabricatorProject $project, $slug, $force) {
    $slug = PhabricatorSlug::normalizeProjectSlug($slug);
    $table = new PhabricatorProjectSlug();
    $project_phid = $project->getPHID();

    if ($force) {
      // If we have the `$force` flag set, we only want to ignore an existing
      // slug if it's for the same project. We'll error on collisions with
      // other projects.
      $current = $table->loadOneWhere(
        'slug = %s AND projectPHID = %s',
        $slug,
        $project_phid);
    } else {
      // Without the `$force` flag, we'll just return without doing anything
      // if any other project already has the slug.
      $current = $table->loadOneWhere(
        'slug = %s',
        $slug);
    }

    if ($current) {
      return;
    }

    return id(new PhabricatorProjectSlug())
      ->setSlug($slug)
      ->setProjectPHID($project_phid)
      ->save();
  }

  private function removeSlugs(PhabricatorProject $project, array $slugs) {
    $slugs = $this->normalizeSlugs($slugs);

    if (!$slugs) {
      return;
    }

    $objects = id(new PhabricatorProjectSlug())->loadAllWhere(
      'projectPHID = %s AND slug IN (%Ls)',
      $project->getPHID(),
      $slugs);

    foreach ($objects as $object) {
      $object->delete();
    }
  }

  private function normalizeSlugs(array $slugs) {
    foreach ($slugs as $key => $slug) {
      $slugs[$key] = PhabricatorSlug::normalizeProjectSlug($slug);
    }

    $slugs = array_unique($slugs);
    $slugs = array_values($slugs);

    return $slugs;
  }

  protected function adjustObjectForPolicyChecks(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $copy = parent::adjustObjectForPolicyChecks($object, $xactions);

    $type_edge = PhabricatorTransactions::TYPE_EDGE;
    $edgetype_member = PhabricatorProjectProjectHasMemberEdgeType::EDGECONST;

    $member_xaction = null;
    foreach ($xactions as $xaction) {
      if ($xaction->getTransactionType() !== $type_edge) {
        continue;
      }

      $edgetype = $xaction->getMetadataValue('edge:type');
      if ($edgetype !== $edgetype_member) {
        continue;
      }

      $member_xaction = $xaction;
    }

    if ($member_xaction) {
      $object_phid = $object->getPHID();

      if ($object_phid) {
        $members = PhabricatorEdgeQuery::loadDestinationPHIDs(
          $object_phid,
          PhabricatorProjectProjectHasMemberEdgeType::EDGECONST);
      } else {
        $members = array();
      }

      $clone_xaction = clone $member_xaction;
      $hint = $this->getPHIDTransactionNewValue($clone_xaction, $members);
      $rule = new PhabricatorProjectMembersPolicyRule();

      $hint = array_fuse($hint);

      PhabricatorPolicyRule::passTransactionHintToRule(
        $copy,
        $rule,
        $hint);
    }

    return $copy;
  }

}
