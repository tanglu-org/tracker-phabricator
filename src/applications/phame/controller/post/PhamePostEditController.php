<?php

final class PhamePostEditController extends PhamePostController {

  public function handleRequest(AphrontRequest $request) {
    $viewer = $request->getViewer();
    $id = $request->getURIData('id');

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb(
      pht('Blogs'),
      $this->getApplicationURI('blog/'));
    if ($id) {
      $post = id(new PhamePostQuery())
        ->setViewer($viewer)
        ->withIDs(array($id))
        ->requireCapabilities(
          array(
            PhabricatorPolicyCapability::CAN_EDIT,
          ))
        ->executeOne();
      if (!$post) {
        return new Aphront404Response();
      }

      $cancel_uri = $this->getApplicationURI('/post/view/'.$id.'/');
      $submit_button = pht('Save Changes');
      $page_title = pht('Edit Post');

      $v_projects = PhabricatorEdgeQuery::loadDestinationPHIDs(
        $post->getPHID(),
        PhabricatorProjectObjectHasProjectEdgeType::EDGECONST);
      $v_projects = array_reverse($v_projects);
      $v_cc = PhabricatorSubscribersQuery::loadSubscribersForPHID(
          $post->getPHID());
      $blog = $post->getBlog();


    } else {
      $blog = id(new PhameBlogQuery())
        ->setViewer($viewer)
        ->withIDs(array($request->getInt('blog')))
        ->requireCapabilities(
          array(
            PhabricatorPolicyCapability::CAN_VIEW,
            PhabricatorPolicyCapability::CAN_EDIT,
          ))
        ->executeOne();
      if (!$blog) {
        return new Aphront404Response();
      }
      $v_projects = array();
      $v_cc = array();

      $post = PhamePost::initializePost($viewer, $blog);
      $cancel_uri = $this->getApplicationURI('/blog/view/'.$blog->getID().'/');

      $submit_button = pht('Create Post');
      $page_title = pht('Create Post');
    }

    $title = $post->getTitle();
    $body = $post->getBody();
    $visibility = $post->getVisibility();

    $e_title       = true;
    $validation_exception = null;
    if ($request->isFormPost()) {
      $title = $request->getStr('title');
      $body = $request->getStr('body');
      $v_projects = $request->getArr('projects');
      $v_cc = $request->getArr('cc');
      $visibility = $request->getInt('visibility');

      $xactions = array(
        id(new PhamePostTransaction())
          ->setTransactionType(PhamePostTransaction::TYPE_TITLE)
          ->setNewValue($title),
        id(new PhamePostTransaction())
          ->setTransactionType(PhamePostTransaction::TYPE_BODY)
          ->setNewValue($body),
        id(new PhamePostTransaction())
          ->setTransactionType(PhamePostTransaction::TYPE_VISIBILITY)
          ->setNewValue($visibility),
        id(new PhamePostTransaction())
          ->setTransactionType(PhabricatorTransactions::TYPE_SUBSCRIBERS)
          ->setNewValue(array('=' => $v_cc)),

      );

      $proj_edge_type = PhabricatorProjectObjectHasProjectEdgeType::EDGECONST;
      $xactions[] = id(new PhamePostTransaction())
        ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
        ->setMetadataValue('edge:type', $proj_edge_type)
        ->setNewValue(array('=' => array_fuse($v_projects)));

      $editor = id(new PhamePostEditor())
        ->setActor($viewer)
        ->setContentSourceFromRequest($request)
        ->setContinueOnNoEffect(true);

      try {
        $editor->applyTransactions($post, $xactions);

        $uri = $post->getViewURI();
        return id(new AphrontRedirectResponse())->setURI($uri);
      } catch (PhabricatorApplicationTransactionValidationException $ex) {
        $validation_exception = $ex;
        $e_title = $validation_exception->getShortMessage(
          PhamePostTransaction::TYPE_TITLE);
      }
    }

    $handle = id(new PhabricatorHandleQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($post->getBlogPHID()))
      ->executeOne();

    $form = id(new AphrontFormView())
      ->setUser($viewer)
      ->addHiddenInput('blog', $request->getInt('blog'))
      ->appendChild(
        id(new AphrontFormMarkupControl())
          ->setLabel(pht('Blog'))
          ->setValue($handle->renderLink()))
      ->appendChild(
        id(new AphrontFormTextControl())
        ->setLabel(pht('Title'))
        ->setName('title')
        ->setValue($title)
        ->setID('post-title')
        ->setError($e_title))
      ->appendChild(
        id(new AphrontFormSelectControl())
        ->setLabel(pht('Visibility'))
        ->setName('visibility')
        ->setValue($visibility)
        ->setOptions(PhameConstants::getPhamePostStatusMap()))
      ->appendChild(
        id(new PhabricatorRemarkupControl())
        ->setLabel(pht('Body'))
        ->setName('body')
        ->setValue($body)
        ->setHeight(AphrontFormTextAreaControl::HEIGHT_VERY_TALL)
        ->setID('post-body')
        ->setUser($viewer)
        ->setDisableMacros(true))
      ->appendControl(
        id(new AphrontFormTokenizerControl())
          ->setLabel(pht('Subscribers'))
          ->setName('cc')
          ->setValue($v_cc)
          ->setUser($viewer)
          ->setDatasource(new PhabricatorMetaMTAMailableDatasource()))
      ->appendControl(
        id(new AphrontFormTokenizerControl())
          ->setLabel(pht('Projects'))
          ->setName('projects')
          ->setValue($v_projects)
          ->setDatasource(new PhabricatorProjectDatasource()))
      ->appendChild(
        id(new AphrontFormSubmitControl())
        ->addCancelButton($cancel_uri)
        ->setValue($submit_button));

    $preview = id(new PHUIRemarkupPreviewPanel())
      ->setHeader($post->getTitle())
      ->setPreviewURI($this->getApplicationURI('post/preview/'))
      ->setControlID('post-body')
      ->setPreviewType(PHUIRemarkupPreviewPanel::DOCUMENT);

    $form_box = id(new PHUIObjectBoxView())
      ->setHeaderText($page_title)
      ->setValidationException($validation_exception)
      ->setForm($form);

    $crumbs->addTextCrumb(
      $blog->getName(),
      $blog->getViewURI());
    $crumbs->addTextCrumb(
      $page_title,
      $cancel_uri);

    return $this->newPage()
      ->setTitle($page_title)
      ->setCrumbs($crumbs)
      ->appendChild(
        array(
          $form_box,
          $preview,
      ));
  }

}
