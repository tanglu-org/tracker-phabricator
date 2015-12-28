<?php

final class PhamePostViewController
  extends PhameLiveController {

  public function handleRequest(AphrontRequest $request) {
    $response = $this->setupLiveEnvironment();
    if ($response) {
      return $response;
    }

    $viewer = $request->getViewer();
    $moved = $request->getStr('moved');

    $post = $this->getPost();
    $blog = $this->getBlog();

    $is_live = $this->getIsLive();
    $is_external = $this->getIsExternal();

    $header = id(new PHUIHeaderView())
      ->setHeader($post->getTitle())
      ->setUser($viewer);

    if (!$is_external) {
      $actions = $this->renderActions($post);

      $action_button = id(new PHUIButtonView())
        ->setTag('a')
        ->setText(pht('Actions'))
        ->setHref('#')
        ->setIconFont('fa-bars')
        ->addClass('phui-mobile-menu')
        ->setDropdownMenu($actions);

      $header->setPolicyObject($post);
      $header->addActionLink($action_button);
    }

    $document = id(new PHUIDocumentViewPro())
      ->setHeader($header);

    if ($moved) {
      $document->appendChild(
        id(new PHUIInfoView())
          ->setSeverity(PHUIInfoView::SEVERITY_NOTICE)
          ->appendChild(pht('Post moved successfully.')));
    }

    if ($post->isDraft()) {
      $document->appendChild(
        id(new PHUIInfoView())
          ->setSeverity(PHUIInfoView::SEVERITY_NOTICE)
          ->setTitle(pht('Draft Post'))
          ->appendChild(
            pht('Only you can see this draft until you publish it. '.
                'Use "Publish" to publish this post.')));
    }

    if (!$post->getBlog()) {
      $document->appendChild(
        id(new PHUIInfoView())
          ->setSeverity(PHUIInfoView::SEVERITY_WARNING)
          ->setTitle(pht('Not On A Blog'))
          ->appendChild(
            pht('This post is not associated with a blog (the blog may have '.
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

    $blogger = id(new PhabricatorPeopleQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($post->getBloggerPHID()))
      ->needProfileImage(true)
      ->executeOne();
    $blogger_profile = $blogger->loadUserProfile();

    $author = phutil_tag(
      'a',
      array(
        'href' => '/p/'.$blogger->getUsername().'/',
      ),
      $blogger->getUsername());

    $date = phabricator_datetime($post->getDatePublished(), $viewer);
    if ($post->isDraft()) {
      $subtitle = pht('Unpublished draft by %s.', $author);
    } else {
      $subtitle = pht('Written by %s on %s.', $author, $date);
    }

    $about = id(new PhameDescriptionView())
      ->setTitle($subtitle)
      ->setDescription($blogger_profile->getTitle())
      ->setImage($blogger->getProfileImageURI())
      ->setImageHref('/p/'.$blogger->getUsername());

    $timeline = $this->buildTransactionTimeline(
      $post,
      id(new PhamePostTransactionQuery())
      ->withTransactionTypes(array(PhabricatorTransactions::TYPE_COMMENT)));
    $timeline = phutil_tag_div('phui-document-view-pro-box', $timeline);

    if ($is_external) {
      $add_comment = null;
    } else {
      $add_comment = $this->buildCommentForm($post);
      $add_comment = phutil_tag_div('mlb mlt', $add_comment);
    }

    $properties = id(new PHUIPropertyListView())
      ->setUser($viewer)
      ->setObject($post);

    $properties->invokeWillRenderEvent();

    $crumbs = $this->buildApplicationCrumbs();

    $page =  $this->newPage()
      ->setTitle($post->getTitle())
      ->setPageObjectPHIDs(array($post->getPHID()))
      ->setCrumbs($crumbs)
      ->appendChild(
        array(
          $document,
          $about,
          $properties,
          $timeline,
          $add_comment,
      ));

    if ($is_live) {
      $page
        ->setShowChrome(false)
        ->setShowFooter(false);
    }

    return $page;
  }

  private function renderActions(PhamePost $post) {
    $viewer = $this->getViewer();

    $actions = id(new PhabricatorActionListView())
      ->setObject($post)
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
        ->setDisabled(!$can_edit));

    $actions->addAction(
      id(new PhabricatorActionView())
        ->setIcon('fa-arrows')
        ->setHref($this->getApplicationURI('post/move/'.$id.'/'))
        ->setName(pht('Move Post'))
        ->setDisabled(!$can_edit)
        ->setWorkflow(true));

    $actions->addAction(
      id(new PhabricatorActionView())
        ->setIcon('fa-history')
        ->setHref($this->getApplicationURI('post/history/'.$id.'/'))
        ->setName(pht('View History')));

    if ($post->isDraft()) {
      $actions->addAction(
        id(new PhabricatorActionView())
          ->setIcon('fa-eye')
          ->setHref($this->getApplicationURI('post/publish/'.$id.'/'))
          ->setName(pht('Publish'))
          ->setDisabled(!$can_edit)
          ->setWorkflow(true));
    } else {
      $actions->addAction(
        id(new PhabricatorActionView())
          ->setIcon('fa-eye-slash')
          ->setHref($this->getApplicationURI('post/unpublish/'.$id.'/'))
          ->setName(pht('Unpublish'))
          ->setDisabled(!$can_edit)
          ->setWorkflow(true));
    }

    if ($post->isDraft()) {
      $live_name = pht('Preview');
    } else {
      $live_name = pht('View Live');
    }

    $actions->addAction(
      id(new PhabricatorActionView())
        ->setUser($viewer)
        ->setIcon('fa-globe')
        ->setHref($post->getLiveURI())
        ->setName($live_name));

    return $actions;
  }

  private function buildCommentForm(PhamePost $post) {
    $viewer = $this->getViewer();

    $draft = PhabricatorDraft::newFromUserAndKey(
      $viewer, $post->getPHID());

    $box = id(new PhabricatorApplicationTransactionCommentView())
      ->setUser($viewer)
      ->setObjectPHID($post->getPHID())
      ->setDraft($draft)
      ->setHeaderText(pht('Add Comment'))
      ->setAction($this->getApplicationURI('post/comment/'.$post->getID().'/'))
      ->setSubmitButtonName(pht('Add Comment'));

    return phutil_tag_div('phui-document-view-pro-box', $box);
  }

}
