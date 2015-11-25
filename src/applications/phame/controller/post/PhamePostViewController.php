<?php

final class PhamePostViewController extends PhamePostController {

  public function handleRequest(AphrontRequest $request) {
    $viewer = $request->getViewer();

    $post = id(new PhamePostQuery())
      ->setViewer($viewer)
      ->withIDs(array($request->getURIData('id')))
      ->executeOne();

    if (!$post) {
      return new Aphront404Response();
    }

    $blog = $post->getBlog();

    $crumbs = $this->buildApplicationCrumbs();
    if ($blog) {
      $crumbs->addTextCrumb(
        $blog->getName(),
        $this->getApplicationURI('blog/view/'.$blog->getID().'/'));
    } else {
      $crumbs->addTextCrumb(
        pht('[No Blog]'),
        null);
    }
    $crumbs->addTextCrumb(
      $post->getTitle(),
      $this->getApplicationURI('post/view/'.$post->getID().'/'));
    $crumbs->setBorder(true);

    $actions = $this->renderActions($post, $viewer);
    $properties = $this->renderProperties($post, $viewer);

    $action_button = id(new PHUIButtonView())
      ->setTag('a')
      ->setText(pht('Actions'))
      ->setHref('#')
      ->setIconFont('fa-bars')
      ->addClass('phui-mobile-menu')
      ->setDropdownMenu($actions);

    $header = id(new PHUIHeaderView())
      ->setHeader($post->getTitle())
      ->setUser($viewer)
      ->setPolicyObject($post)
      ->addActionLink($action_button);

    $document = id(new PHUIDocumentViewPro())
      ->setHeader($header)
      ->setPropertyList($properties);

    if ($post->isDraft()) {
      $document->appendChild(
        id(new PHUIInfoView())
          ->setSeverity(PHUIInfoView::SEVERITY_NOTICE)
          ->setTitle(pht('Draft Post'))
          ->appendChild(
            pht(
              'Only you can see this draft until you publish it. '.
              'Use "Preview / Publish" to publish this post.')));
    }

    if (!$post->getBlog()) {
      $document->appendChild(
        id(new PHUIInfoView())
          ->setSeverity(PHUIInfoView::SEVERITY_WARNING)
          ->setTitle(pht('Not On A Blog'))
          ->appendChild(
            pht(
              'This post is not associated with a blog (the blog may have '.
              'been deleted). Use "Move Post" to move it to a new blog.')));
    }

    $engine = id(new PhabricatorMarkupEngine())
      ->setViewer($viewer)
      ->addObject($post, PhamePost::MARKUP_FIELD_BODY)
      ->process();

    $document->appendChild(
      phutil_tag(
         'div',
        array(
          'class' => 'phabricator-remarkup',
        ),
        $engine->getOutput($post, PhamePost::MARKUP_FIELD_BODY)));

    $timeline = $this->buildTransactionTimeline(
      $post,
      id(new PhamePostTransactionQuery())
      ->withTransactionTypes(array(PhabricatorTransactions::TYPE_COMMENT)));
    $timeline = phutil_tag_div('phui-document-view-pro-box', $timeline);

    $add_comment = $this->buildCommentForm($post);

    return $this->newPage()
      ->setTitle($post->getTitle())
      ->addClass('pro-white-background')
      ->setPageObjectPHIDs(array($post->getPHID()))
      ->setCrumbs($crumbs)
      ->appendChild(
        array(
          $document,
          $timeline,
          $add_comment,
      ));
  }

  private function renderActions(
    PhamePost $post,
    PhabricatorUser $viewer) {

      $actions = id(new PhabricatorActionListView())
        ->setObject($post)
        ->setObjectURI($this->getRequest()->getRequestURI())
        ->setUser($viewer);

    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $viewer,
      $post,
      PhabricatorPolicyCapability::CAN_EDIT);

    $id = $post->getID();

    $actions->addAction(
      id(new PhabricatorActionView())
        ->setIcon('fa-pencil')
        ->setHref($this->getApplicationURI('post/edit/'.$id.'/'))
        ->setName(pht('Edit Post'))
        ->setDisabled(!$can_edit)
        ->setWorkflow(!$can_edit));

    $actions->addAction(
      id(new PhabricatorActionView())
        ->setIcon('fa-arrows')
        ->setHref($this->getApplicationURI('post/move/'.$id.'/'))
        ->setName(pht('Move Post'))
        ->setDisabled(!$can_edit)
        ->setWorkflow(!$can_edit));

    if ($post->isDraft()) {
      $actions->addAction(
        id(new PhabricatorActionView())
          ->setIcon('fa-eye')
          ->setHref($this->getApplicationURI('post/publish/'.$id.'/'))
          ->setDisabled(!$can_edit)
          ->setName(pht('Preview / Publish')));
    } else {
      $actions->addAction(
        id(new PhabricatorActionView())
          ->setIcon('fa-eye-slash')
          ->setHref($this->getApplicationURI('post/unpublish/'.$id.'/'))
          ->setName(pht('Unpublish'))
          ->setDisabled(!$can_edit)
          ->setWorkflow(true));
    }

    $actions->addAction(
      id(new PhabricatorActionView())
        ->setIcon('fa-times')
        ->setHref($this->getApplicationURI('post/delete/'.$id.'/'))
        ->setName(pht('Delete Post'))
        ->setDisabled(!$can_edit)
        ->setWorkflow(true));

    $blog = $post->getBlog();
    $can_view_live = $blog && !$post->isDraft();

    if ($can_view_live) {
      $live_uri = $blog->getLiveURI($post);
    } else {
      $live_uri = 'post/notlive/'.$post->getID().'/';
      $live_uri = $this->getApplicationURI($live_uri);
    }

    $actions->addAction(
      id(new PhabricatorActionView())
        ->setUser($viewer)
        ->setIcon('fa-globe')
        ->setHref($live_uri)
        ->setName(pht('View Live'))
        ->setDisabled(!$can_view_live)
        ->setWorkflow(!$can_view_live));

    return $actions;
  }

  private function renderProperties(
    PhamePost $post,
    PhabricatorUser $viewer) {

    $properties = id(new PHUIPropertyListView())
      ->setUser($viewer)
      ->setObject($post);

    $properties->addProperty(
      pht('Blog'),
      $viewer->renderHandle($post->getBlogPHID()));

    $properties->addProperty(
      pht('Blogger'),
      $viewer->renderHandle($post->getBloggerPHID()));

    $properties->addProperty(
      pht('Published'),
      $post->isDraft()
        ? pht('Draft')
        : phabricator_datetime($post->getDatePublished(), $viewer));

    $properties->invokeWillRenderEvent();

    return $properties;
  }

  private function buildCommentForm(PhamePost $post) {
    $viewer = $this->getViewer();

    $is_serious = PhabricatorEnv::getEnvConfig('phabricator.serious-business');

    $add_comment_header = $is_serious
      ? pht('Add Comment')
      : pht('Derp Text');

    $draft = PhabricatorDraft::newFromUserAndKey(
      $viewer, $post->getPHID());

    $box = id(new PhabricatorApplicationTransactionCommentView())
      ->setUser($viewer)
      ->setObjectPHID($post->getPHID())
      ->setDraft($draft)
      ->setHeaderText($add_comment_header)
      ->setAction($this->getApplicationURI('post/comment/'.$post->getID().'/'))
      ->setSubmitButtonName(pht('Add Comment'));

    return phutil_tag_div('phui-document-view-pro-box', $box);
  }

}
