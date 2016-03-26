<?php

/**
 * @concrete-extensible
 */
class PhabricatorApplicationTransactionCommentView extends AphrontView {

  private $submitButtonName;
  private $action;

  private $previewPanelID;
  private $previewTimelineID;
  private $previewToggleID;
  private $formID;
  private $commentID;
  private $draft;
  private $requestURI;
  private $showPreview = true;
  private $objectPHID;
  private $headerText;
  private $noPermission;
  private $fullWidth;

  private $currentVersion;
  private $versionedDraft;
  private $commentActions;
  private $transactionTimeline;

  public function setObjectPHID($object_phid) {
    $this->objectPHID = $object_phid;
    return $this;
  }

  public function getObjectPHID() {
    return $this->objectPHID;
  }

  public function setShowPreview($show_preview) {
    $this->showPreview = $show_preview;
    return $this;
  }

  public function getShowPreview() {
    return $this->showPreview;
  }

  public function setRequestURI(PhutilURI $request_uri) {
    $this->requestURI = $request_uri;
    return $this;
  }
  public function getRequestURI() {
    return $this->requestURI;
  }

  public function setCurrentVersion($current_version) {
    $this->currentVersion = $current_version;
    return $this;
  }

  public function getCurrentVersion() {
    return $this->currentVersion;
  }

  public function setVersionedDraft(
    PhabricatorVersionedDraft $versioned_draft) {
    $this->versionedDraft = $versioned_draft;
    return $this;
  }

  public function getVersionedDraft() {
    return $this->versionedDraft;
  }

  public function setDraft(PhabricatorDraft $draft) {
    $this->draft = $draft;
    return $this;
  }

  public function getDraft() {
    return $this->draft;
  }

  public function setSubmitButtonName($submit_button_name) {
    $this->submitButtonName = $submit_button_name;
    return $this;
  }

  public function getSubmitButtonName() {
    return $this->submitButtonName;
  }

  public function setAction($action) {
    $this->action = $action;
    return $this;
  }

  public function getAction() {
    return $this->action;
  }

  public function setHeaderText($text) {
    $this->headerText = $text;
    return $this;
  }

  public function setFullWidth($fw) {
    $this->fullWidth = $fw;
    return $this;
  }

  public function setCommentActions(array $comment_actions) {
    assert_instances_of($comment_actions, 'PhabricatorEditEngineCommentAction');
    $this->commentActions = $comment_actions;
    return $this;
  }

  public function getCommentActions() {
    return $this->commentActions;
  }

  public function setNoPermission($no_permission) {
    $this->noPermission = $no_permission;
    return $this;
  }

  public function getNoPermission() {
    return $this->noPermission;
  }

  public function setTransactionTimeline(
    PhabricatorApplicationTransactionView $timeline) {

    $timeline->setQuoteTargetID($this->getCommentID());
    if ($this->getNoPermission()) {
      $timeline->setShouldTerminate(true);
    }

    $this->transactionTimeline = $timeline;
    return $this;
  }

  public function render() {
    if ($this->getNoPermission()) {
      return null;
    }

    $user = $this->getUser();
    if (!$user->isLoggedIn()) {
      $uri = id(new PhutilURI('/login/'))
        ->setQueryParam('next', (string)$this->getRequestURI());
      return id(new PHUIObjectBoxView())
        ->setFlush(true)
        ->setHeaderText(pht('Add Comment'))
        ->appendChild(
          javelin_tag(
            'a',
            array(
              'class' => 'login-to-comment button',
              'href' => $uri,
            ),
            pht('Login to Comment')));
    }

    $data = array();

    $comment = $this->renderCommentPanel();

    if ($this->getShowPreview()) {
      $preview = $this->renderPreviewPanel();
    } else {
      $preview = null;
    }

    if (!$this->getCommentActions()) {
      Javelin::initBehavior(
        'phabricator-transaction-comment-form',
        array(
          'formID'        => $this->getFormID(),
          'timelineID'    => $this->getPreviewTimelineID(),
          'panelID'       => $this->getPreviewPanelID(),
          'showPreview'   => $this->getShowPreview(),
          'actionURI'     => $this->getAction(),
        ));
    }

    $comment_box = id(new PHUIObjectBoxView())
      ->setFlush(true)
      ->setHeaderText($this->headerText)
      ->appendChild($comment);

    return array($comment_box, $preview);
  }

  private function renderCommentPanel() {
    $draft_comment = '';
    $draft_key = null;
    if ($this->getDraft()) {
      $draft_comment = $this->getDraft()->getDraft();
      $draft_key = $this->getDraft()->getDraftKey();
    }

    $versioned_draft = $this->getVersionedDraft();
    if ($versioned_draft) {
      $draft_comment = $versioned_draft->getProperty('comment', '');
    }

    if (!$this->getObjectPHID()) {
      throw new PhutilInvalidStateException('setObjectPHID', 'render');
    }

    $version_key = PhabricatorVersionedDraft::KEY_VERSION;
    $version_value = $this->getCurrentVersion();

    $form = id(new AphrontFormView())
      ->setUser($this->getUser())
      ->addSigil('transaction-append')
      ->setWorkflow(true)
      ->setFullWidth($this->fullWidth)
      ->setMetadata(
        array(
          'objectPHID' => $this->getObjectPHID(),
        ))
      ->setAction($this->getAction())
      ->setID($this->getFormID())
      ->addHiddenInput('__draft__', $draft_key)
      ->addHiddenInput($version_key, $version_value);

    $comment_actions = $this->getCommentActions();
    if ($comment_actions) {
      $action_map = array();
      $type_map = array();

      $comment_actions = mpull($comment_actions, null, 'getKey');

      $draft_actions = array();
      $draft_keys = array();
      if ($versioned_draft) {
        $draft_actions = $versioned_draft->getProperty('actions', array());

        if (!is_array($draft_actions)) {
          $draft_actions = array();
        }

        foreach ($draft_actions as $action) {
          $type = idx($action, 'type');
          $comment_action = idx($comment_actions, $type);
          if (!$comment_action) {
            continue;
          }

          $value = idx($action, 'value');
          $comment_action->setValue($value);

          $draft_keys[] = $type;
        }
      }

      foreach ($comment_actions as $key => $comment_action) {
        $key = $comment_action->getKey();
        $action_map[$key] = array(
          'key' => $key,
          'label' => $comment_action->getLabel(),
          'type' => $comment_action->getPHUIXControlType(),
          'spec' => $comment_action->getPHUIXControlSpecification(),
          'initialValue' => $comment_action->getInitialValue(),
        );

        $type_map[$key] = $comment_action;
      }

      $options = array();
      $options['+'] = pht('Add Action...');
      foreach ($action_map as $key => $item) {
        $options[$key] = $item['label'];
      }

      $action_id = celerity_generate_unique_node_id();
      $input_id = celerity_generate_unique_node_id();
      $place_id = celerity_generate_unique_node_id();

      $form->appendChild(
        phutil_tag(
          'input',
          array(
            'type' => 'hidden',
            'name' => 'editengine.actions',
            'id' => $input_id,
          )));

      $form->appendChild(
        id(new AphrontFormSelectControl())
          ->setLabel(pht('Actions'))
          ->setID($action_id)
          ->setOptions($options));

      // This is an empty placeholder node so we know where to insert the
      // new actions.
      $form->appendChild(
        phutil_tag(
          'div',
          array(
            'id' => $place_id,
          )));

      Javelin::initBehavior(
        'comment-actions',
        array(
          'actionID' => $action_id,
          'inputID' => $input_id,
          'formID' => $this->getFormID(),
          'placeID' => $place_id,
          'panelID' => $this->getPreviewPanelID(),
          'timelineID' => $this->getPreviewTimelineID(),
          'actions' => $action_map,
          'showPreview' => $this->getShowPreview(),
          'actionURI' => $this->getAction(),
          'drafts' => $draft_keys,
        ));
    }

    $form
      ->appendChild(
        id(new PhabricatorRemarkupControl())
          ->setID($this->getCommentID())
          ->setName('comment')
          ->setLabel(pht('Comment'))
          ->setUser($this->getUser())
          ->setValue($draft_comment))
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue($this->getSubmitButtonName()));

    return $form;
  }

  private function renderPreviewPanel() {

    $preview = id(new PHUITimelineView())
      ->setID($this->getPreviewTimelineID());

    return phutil_tag(
      'div',
      array(
        'id'    => $this->getPreviewPanelID(),
        'style' => 'display: none',
      ),
      $preview);
  }

  private function getPreviewPanelID() {
    if (!$this->previewPanelID) {
      $this->previewPanelID = celerity_generate_unique_node_id();
    }
    return $this->previewPanelID;
  }

  private function getPreviewTimelineID() {
    if (!$this->previewTimelineID) {
      $this->previewTimelineID = celerity_generate_unique_node_id();
    }
    return $this->previewTimelineID;
  }

  public function setFormID($id) {
    $this->formID = $id;
    return $this;
  }

  private function getFormID() {
    if (!$this->formID) {
      $this->formID = celerity_generate_unique_node_id();
    }
    return $this->formID;
  }

  private function getStatusID() {
    if (!$this->statusID) {
      $this->statusID = celerity_generate_unique_node_id();
    }
    return $this->statusID;
  }

  private function getCommentID() {
    if (!$this->commentID) {
      $this->commentID = celerity_generate_unique_node_id();
    }
    return $this->commentID;
  }

}
