<?php

final class PhabricatorProject extends PhabricatorProjectDAO
  implements
    PhabricatorApplicationTransactionInterface,
    PhabricatorFlaggableInterface,
    PhabricatorPolicyInterface,
    PhabricatorExtendedPolicyInterface,
    PhabricatorSubscribableInterface,
    PhabricatorCustomFieldInterface,
    PhabricatorDestructibleInterface,
    PhabricatorFulltextInterface {

  protected $name;
  protected $status = PhabricatorProjectStatus::STATUS_ACTIVE;
  protected $authorPHID;
  protected $primarySlug;
  protected $profileImagePHID;
  protected $icon;
  protected $color;
  protected $mailKey;

  protected $viewPolicy;
  protected $editPolicy;
  protected $joinPolicy;
  protected $isMembershipLocked;

  protected $parentProjectPHID;
  protected $hasWorkboard;
  protected $hasMilestones;
  protected $hasSubprojects;
  protected $milestoneNumber;

  protected $projectPath;
  protected $projectDepth;
  protected $projectPathKey;

  private $memberPHIDs = self::ATTACHABLE;
  private $watcherPHIDs = self::ATTACHABLE;
  private $sparseWatchers = self::ATTACHABLE;
  private $sparseMembers = self::ATTACHABLE;
  private $customFields = self::ATTACHABLE;
  private $profileImageFile = self::ATTACHABLE;
  private $slugs = self::ATTACHABLE;
  private $parentProject = self::ATTACHABLE;

  const DEFAULT_ICON = 'fa-briefcase';
  const DEFAULT_COLOR = 'blue';

  const TABLE_DATASOURCE_TOKEN = 'project_datasourcetoken';

  public static function initializeNewProject(PhabricatorUser $actor) {
    $app = id(new PhabricatorApplicationQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withClasses(array('PhabricatorProjectApplication'))
      ->executeOne();

    $view_policy = $app->getPolicy(
      ProjectDefaultViewCapability::CAPABILITY);
    $edit_policy = $app->getPolicy(
      ProjectDefaultEditCapability::CAPABILITY);
    $join_policy = $app->getPolicy(
      ProjectDefaultJoinCapability::CAPABILITY);

    return id(new PhabricatorProject())
      ->setAuthorPHID($actor->getPHID())
      ->setIcon(self::DEFAULT_ICON)
      ->setColor(self::DEFAULT_COLOR)
      ->setViewPolicy($view_policy)
      ->setEditPolicy($edit_policy)
      ->setJoinPolicy($join_policy)
      ->setIsMembershipLocked(0)
      ->attachMemberPHIDs(array())
      ->attachSlugs(array())
      ->setHasWorkboard(0)
      ->setHasMilestones(0)
      ->setHasSubprojects(0)
      ->attachParentProject(null);
  }

  public function getCapabilities() {
    return array(
      PhabricatorPolicyCapability::CAN_VIEW,
      PhabricatorPolicyCapability::CAN_EDIT,
      PhabricatorPolicyCapability::CAN_JOIN,
    );
  }

  public function getPolicy($capability) {
    switch ($capability) {
      case PhabricatorPolicyCapability::CAN_VIEW:
        return $this->getViewPolicy();
      case PhabricatorPolicyCapability::CAN_EDIT:
        return $this->getEditPolicy();
      case PhabricatorPolicyCapability::CAN_JOIN:
        return $this->getJoinPolicy();
    }
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    $can_edit = PhabricatorPolicyCapability::CAN_EDIT;

    switch ($capability) {
      case PhabricatorPolicyCapability::CAN_VIEW:
        if ($this->isUserMember($viewer->getPHID())) {
          // Project members can always view a project.
          return true;
        }
        break;
      case PhabricatorPolicyCapability::CAN_EDIT:
        $parent = $this->getParentProject();
        if ($parent) {
          $can_edit_parent = PhabricatorPolicyFilter::hasCapability(
            $viewer,
            $parent,
            $can_edit);
          if ($can_edit_parent) {
            return true;
          }
        }
        break;
      case PhabricatorPolicyCapability::CAN_JOIN:
        if (PhabricatorPolicyFilter::hasCapability($viewer, $this, $can_edit)) {
          // Project editors can always join a project.
          return true;
        }
        break;
    }

    return false;
  }

  public function describeAutomaticCapability($capability) {

    // TODO: Clarify the additional rules that parent and subprojects imply.

    switch ($capability) {
      case PhabricatorPolicyCapability::CAN_VIEW:
        return pht('Members of a project can always view it.');
      case PhabricatorPolicyCapability::CAN_JOIN:
        return pht('Users who can edit a project can always join it.');
    }
    return null;
  }

  public function getExtendedPolicy($capability, PhabricatorUser $viewer) {
    $extended = array();

    switch ($capability) {
      case PhabricatorPolicyCapability::CAN_VIEW:
        $parent = $this->getParentProject();
        if ($parent) {
          $extended[] = array(
            $parent,
            PhabricatorPolicyCapability::CAN_VIEW,
          );
        }
        break;
    }

    return $extended;
  }


  public function isUserMember($user_phid) {
    if ($this->memberPHIDs !== self::ATTACHABLE) {
      return in_array($user_phid, $this->memberPHIDs);
    }
    return $this->assertAttachedKey($this->sparseMembers, $user_phid);
  }

  public function setIsUserMember($user_phid, $is_member) {
    if ($this->sparseMembers === self::ATTACHABLE) {
      $this->sparseMembers = array();
    }
    $this->sparseMembers[$user_phid] = $is_member;
    return $this;
  }

  protected function getConfiguration() {
    return array(
      self::CONFIG_AUX_PHID => true,
      self::CONFIG_COLUMN_SCHEMA => array(
        'name' => 'sort128',
        'status' => 'text32',
        'primarySlug' => 'text128?',
        'isMembershipLocked' => 'bool',
        'profileImagePHID' => 'phid?',
        'icon' => 'text32',
        'color' => 'text32',
        'mailKey' => 'bytes20',
        'joinPolicy' => 'policy',
        'parentProjectPHID' => 'phid?',
        'hasWorkboard' => 'bool',
        'hasMilestones' => 'bool',
        'hasSubprojects' => 'bool',
        'milestoneNumber' => 'uint32?',
        'projectPath' => 'hashpath64',
        'projectDepth' => 'uint32',
        'projectPathKey' => 'bytes4',
      ),
      self::CONFIG_KEY_SCHEMA => array(
        'key_phid' => null,
        'phid' => array(
          'columns' => array('phid'),
          'unique' => true,
        ),
        'key_icon' => array(
          'columns' => array('icon'),
        ),
        'key_color' => array(
          'columns' => array('color'),
        ),
        'name' => array(
          'columns' => array('name'),
          'unique' => true,
        ),
        'key_milestone' => array(
          'columns' => array('parentProjectPHID', 'milestoneNumber'),
          'unique' => true,
        ),
        'key_primaryslug' => array(
          'columns' => array('primarySlug'),
          'unique' => true,
        ),
        'key_path' => array(
          'columns' => array('projectPath', 'projectDepth'),
        ),
        'key_pathkey' => array(
          'columns' => array('projectPathKey'),
          'unique' => true,
        ),
      ),
    ) + parent::getConfiguration();
  }

  public function generatePHID() {
    return PhabricatorPHID::generateNewPHID(
      PhabricatorProjectProjectPHIDType::TYPECONST);
  }

  public function attachMemberPHIDs(array $phids) {
    $this->memberPHIDs = $phids;
    return $this;
  }

  public function getMemberPHIDs() {
    return $this->assertAttached($this->memberPHIDs);
  }

  public function isArchived() {
    return ($this->getStatus() == PhabricatorProjectStatus::STATUS_ARCHIVED);
  }

  public function getProfileImageURI() {
    return $this->getProfileImageFile()->getBestURI();
  }

  public function attachProfileImageFile(PhabricatorFile $file) {
    $this->profileImageFile = $file;
    return $this;
  }

  public function getProfileImageFile() {
    return $this->assertAttached($this->profileImageFile);
  }


  public function isUserWatcher($user_phid) {
    if ($this->watcherPHIDs !== self::ATTACHABLE) {
      return in_array($user_phid, $this->watcherPHIDs);
    }
    return $this->assertAttachedKey($this->sparseWatchers, $user_phid);
  }

  public function setIsUserWatcher($user_phid, $is_watcher) {
    if ($this->sparseWatchers === self::ATTACHABLE) {
      $this->sparseWatchers = array();
    }
    $this->sparseWatchers[$user_phid] = $is_watcher;
    return $this;
  }

  public function attachWatcherPHIDs(array $phids) {
    $this->watcherPHIDs = $phids;
    return $this;
  }

  public function getWatcherPHIDs() {
    return $this->assertAttached($this->watcherPHIDs);
  }

  public function attachSlugs(array $slugs) {
    $this->slugs = $slugs;
    return $this;
  }

  public function getSlugs() {
    return $this->assertAttached($this->slugs);
  }

  public function getColor() {
    if ($this->isArchived()) {
      return PHUITagView::COLOR_DISABLED;
    }

    return $this->color;
  }

  public function save() {
    if (!$this->getMailKey()) {
      $this->setMailKey(Filesystem::readRandomCharacters(20));
    }

    if (!strlen($this->getPHID())) {
      $this->setPHID($this->generatePHID());
    }

    if (!strlen($this->getProjectPathKey())) {
      $hash = PhabricatorHash::digestForIndex($this->getPHID());
      $hash = substr($hash, 0, 4);
      $this->setProjectPathKey($hash);
    }

    $path = array();
    $depth = 0;
    if ($this->parentProjectPHID) {
      $parent = $this->getParentProject();
      $path[] = $parent->getProjectPath();
      $depth = $parent->getProjectDepth() + 1;
    }
    $path[] = $this->getProjectPathKey();
    $path = implode('', $path);

    $limit = self::getProjectDepthLimit();
    if ($depth >= $limit) {
      throw new Exception(pht('Project depth is too great.'));
    }

    $this->setProjectPath($path);
    $this->setProjectDepth($depth);

    $this->openTransaction();
      $result = parent::save();
      $this->updateDatasourceTokens();
    $this->saveTransaction();

    return $result;
  }

  public static function getProjectDepthLimit() {
    // This is limited by how many path hashes we can fit in the path
    // column.
    return 16;
  }

  public function updateDatasourceTokens() {
    $table = self::TABLE_DATASOURCE_TOKEN;
    $conn_w = $this->establishConnection('w');
    $id = $this->getID();

    $slugs = queryfx_all(
      $conn_w,
      'SELECT * FROM %T WHERE projectPHID = %s',
      id(new PhabricatorProjectSlug())->getTableName(),
      $this->getPHID());

    $all_strings = ipull($slugs, 'slug');
    $all_strings[] = $this->getName();
    $all_strings = implode(' ', $all_strings);

    $tokens = PhabricatorTypeaheadDatasource::tokenizeString($all_strings);

    $sql = array();
    foreach ($tokens as $token) {
      $sql[] = qsprintf($conn_w, '(%d, %s)', $id, $token);
    }

    $this->openTransaction();
      queryfx(
        $conn_w,
        'DELETE FROM %T WHERE projectID = %d',
        $table,
        $id);

      foreach (PhabricatorLiskDAO::chunkSQL($sql) as $chunk) {
        queryfx(
          $conn_w,
          'INSERT INTO %T (projectID, token) VALUES %Q',
          $table,
          $chunk);
      }
    $this->saveTransaction();
  }

  public function isMilestone() {
    return ($this->getMilestoneNumber() !== null);
  }

  public function getParentProject() {
    return $this->assertAttached($this->parentProject);
  }

  public function attachParentProject(PhabricatorProject $project = null) {
    $this->parentProject = $project;
    return $this;
  }

  public function getAncestorProjectPaths() {
    $parts = array();

    $path = $this->getProjectPath();
    $parent_length = (strlen($path) - 4);

    for ($ii = $parent_length; $ii > 0; $ii -= 4) {
      $parts[] = substr($path, 0, $ii);
    }

    return $parts;
  }

  public function getAncestorProjects() {
    $ancestors = array();

    $cursor = $this->getParentProject();
    while ($cursor) {
      $ancestors[] = $cursor;
      $cursor = $cursor->getParentProject();
    }

    return $ancestors;
  }


/* -(  PhabricatorSubscribableInterface  )----------------------------------- */


  public function isAutomaticallySubscribed($phid) {
    return false;
  }

  public function shouldShowSubscribersProperty() {
    return false;
  }

  public function shouldAllowSubscription($phid) {
    return $this->isUserMember($phid) &&
           !$this->isUserWatcher($phid);
  }


/* -(  PhabricatorCustomFieldInterface  )------------------------------------ */


  public function getCustomFieldSpecificationForRole($role) {
    return PhabricatorEnv::getEnvConfig('projects.fields');
  }

  public function getCustomFieldBaseClass() {
    return 'PhabricatorProjectCustomField';
  }

  public function getCustomFields() {
    return $this->assertAttached($this->customFields);
  }

  public function attachCustomFields(PhabricatorCustomFieldAttachment $fields) {
    $this->customFields = $fields;
    return $this;
  }


/* -(  PhabricatorApplicationTransactionInterface  )------------------------- */


  public function getApplicationTransactionEditor() {
    return new PhabricatorProjectTransactionEditor();
  }

  public function getApplicationTransactionObject() {
    return $this;
  }

  public function getApplicationTransactionTemplate() {
    return new PhabricatorProjectTransaction();
  }

  public function willRenderTimeline(
    PhabricatorApplicationTransactionView $timeline,
    AphrontRequest $request) {

    return $timeline;
  }


/* -(  PhabricatorDestructibleInterface  )----------------------------------- */


  public function destroyObjectPermanently(
    PhabricatorDestructionEngine $engine) {

    $this->openTransaction();
      $this->delete();

      $columns = id(new PhabricatorProjectColumn())
        ->loadAllWhere('projectPHID = %s', $this->getPHID());
      foreach ($columns as $column) {
        $engine->destroyObject($column);
      }

      $slugs = id(new PhabricatorProjectSlug())
        ->loadAllWhere('projectPHID = %s', $this->getPHID());
      foreach ($slugs as $slug) {
        $slug->delete();
      }

    $this->saveTransaction();
  }


/* -(  PhabricatorFulltextInterface  )--------------------------------------- */


  public function newFulltextEngine() {
    return new PhabricatorProjectFulltextEngine();
  }

}
