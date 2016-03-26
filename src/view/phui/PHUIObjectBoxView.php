<?php

final class PHUIObjectBoxView extends AphrontTagView {

  private $headerText;
  private $color;
  private $background;
  private $formErrors = null;
  private $formSaved = false;
  private $infoView;
  private $form;
  private $validationException;
  private $header;
  private $flush;
  private $actionListID;
  private $objectList;
  private $table;
  private $collapsed = false;
  private $anchor;

  private $showAction;
  private $hideAction;
  private $showHideHref;
  private $showHideContent;
  private $showHideOpen;

  private $tabs = array();
  private $tabMap = null;
  private $tabLists = array();
  private $propertyLists = array();
  private $propertyList = null;

  const COLOR_RED = 'red';
  const COLOR_BLUE = 'blue';
  const COLOR_GREEN = 'green';
  const COLOR_YELLOW = 'yellow';

  const BLUE = 'phui-box-blue';
  const BLUE_PROPERTY = 'phui-box-blue-property';
  const GREY = 'phui-box-grey';

  public function addPropertyList(
    PHUIPropertyListView $property_list,
    $tab = null) {

    if (!($tab instanceof PHUIListItemView) &&
        ($tab !== null)) {
      assert_stringlike($tab);
      $tab = id(new PHUIListItemView())->setName($tab);
    }

    if ($tab) {
      if ($tab->getKey()) {
        $key = $tab->getKey();
      } else {
        $key = 'tab.default.'.spl_object_hash($tab);
        $tab->setKey($key);
      }
    } else {
      $key = 'tab.default';
    }

    if ($tab) {
      if (empty($this->tabs[$key])) {
        $tab->addSigil('phui-object-box-tab');
        $tab->setMetadata(
          array(
            'tabKey' => $key,
          ));

        if (!$tab->getHref()) {
          $tab->setHref('#');
        }

        if (!$tab->getType()) {
          $tab->setType(PHUIListItemView::TYPE_LINK);
        }

        $this->tabs[$key] = $tab;
      }
    }

    $this->propertyLists[$key][] = $property_list;

    $action_list = $property_list->getActionList();
    if ($action_list) {
      $this->actionListID = celerity_generate_unique_node_id();
      $action_list->setId($this->actionListID);
    }

    return $this;
  }

  public function setHeaderText($text) {
    $this->headerText = $text;
    return $this;
  }

  public function setColor($color) {
    $this->color = $color;
    return $this;
  }

  public function setBackground($color) {
    $this->background = $color;
    return $this;
  }

  public function setFormErrors(array $errors, $title = null) {
    if ($errors) {
      $this->formErrors = id(new PHUIInfoView())
        ->setTitle($title)
        ->setErrors($errors);
    }
    return $this;
  }

  public function setFormSaved($saved, $text = null) {
    if (!$text) {
      $text = pht('Changes saved.');
    }
    if ($saved) {
      $save = id(new PHUIInfoView())
        ->setSeverity(PHUIInfoView::SEVERITY_NOTICE)
        ->appendChild($text);
      $this->formSaved = $save;
    }
    return $this;
  }

  public function setInfoView(PHUIInfoView $view) {
    $this->infoView = $view;
    return $this;
  }

  public function setForm($form) {
    $this->form = $form;
    return $this;
  }

  public function setHeader($header) {
    $this->header = $header;
    return $this;
  }

  public function setFlush($flush) {
    $this->flush = $flush;
    return $this;
  }

  public function setObjectList($list) {
    $this->objectList = $list;
    return $this;
  }

  public function setTable($table) {
    $this->collapsed = true;
    $this->table = $table;
    return $this;
  }

  public function setCollapsed($collapsed) {
    $this->collapsed = $collapsed;
    return $this;
  }

  public function setAnchor(PhabricatorAnchorView $anchor) {
    $this->anchor = $anchor;
    return $this;
  }

  public function setShowHide($show, $hide, $content, $href, $open = false) {
    $this->showAction = $show;
    $this->hideAction = $hide;
    $this->showHideContent = $content;
    $this->showHideHref = $href;
    $this->showHideOpen = $open;
    return $this;
  }

  public function setValidationException(
    PhabricatorApplicationTransactionValidationException $ex = null) {
    $this->validationException = $ex;
    return $this;
  }

  public function willRender() {
    $tab_lists = array();
    $property_lists = array();
    $tab_map = array();

    $default_key = 'tab.default';

    // Find the selected tab, or select the first tab if none are selected.
    if ($this->tabs) {
      $selected_tab = null;
      foreach ($this->tabs as $key => $tab) {
        if ($tab->getSelected()) {
          $selected_tab = $key;
          break;
        }
      }
      if ($selected_tab === null) {
        head($this->tabs)->setSelected(true);
        $selected_tab = head_key($this->tabs);
      }
    }

    foreach ($this->propertyLists as $key => $list) {
      $group = new PHUIPropertyGroupView();
      $i = 0;
      foreach ($list as $item) {
        $group->addPropertyList($item);
        if ($i > 0) {
          $item->addClass('phui-property-list-section-noninitial');
        }
        $i++;
      }

      if ($this->tabs && $key != $default_key) {
        $tab_id = celerity_generate_unique_node_id();
        $tab_map[$key] = $tab_id;

        if ($key === $selected_tab) {
          $style = null;
        } else {
          $style = 'display: none';
        }

        $tab_lists[] = phutil_tag(
          'div',
          array(
            'style' => $style,
            'id' => $tab_id,
          ),
          $group);
      } else {
        if ($this->tabs) {
          $group->addClass('phui-property-group-noninitial');
        }
        $property_lists[] = $group;
      }
      $this->propertyList = $property_lists;
      $this->tabMap = $tab_map;
      $this->tabLists = $tab_lists;
    }
  }

  protected function getTagAttributes() {
    $classes = array();
    $classes[] = 'phui-box';
    $classes[] = 'phui-box-border';
    $classes[] = 'phui-object-box';
    $classes[] = 'mlt mll mlr';

    if ($this->color) {
      $classes[] = 'phui-object-box-'.$this->color;
    }

    if ($this->collapsed) {
      $classes[] = 'phui-object-box-collapsed';
    }

    if ($this->flush) {
      $classes[] = 'phui-object-box-flush';
    }

    if ($this->background) {
      $classes[] = $this->background;
    }

    $sigil = null;
    $metadata = null;
    if ($this->tabs) {
      $sigil = 'phui-object-box';
      $metadata = array(
        'tabMap' => $this->tabMap,
      );
    }

    return array(
      'class' => implode(' ', $classes),
      'sigil' => $sigil,
      'meta' => $metadata,
    );
  }

  protected function getTagContent() {
    require_celerity_resource('phui-box-css');
    require_celerity_resource('phui-object-box-css');

    $header = $this->header;

    if ($this->headerText) {
      $header = id(new PHUIHeaderView())
        ->setHeader($this->headerText);
    }

    $showhide = null;
    if ($this->showAction !== null) {
      if (!$header) {
        $header = id(new PHUIHeaderView());
      }

      Javelin::initBehavior('phabricator-reveal-content');

      $hide_action_id = celerity_generate_unique_node_id();
      $show_action_id = celerity_generate_unique_node_id();
      $content_id = celerity_generate_unique_node_id();

      $hide_style = ($this->showHideOpen ? 'display: none;': null);
      $show_style = ($this->showHideOpen ? null : 'display: none;');
      $hide_action = id(new PHUIButtonView())
        ->setTag('a')
        ->addSigil('reveal-content')
        ->setID($hide_action_id)
        ->setStyle($hide_style)
        ->setHref($this->showHideHref)
        ->setMetaData(
          array(
            'hideIDs' => array($hide_action_id),
            'showIDs' => array($content_id, $show_action_id),
          ))
        ->setText($this->showAction);

      $show_action = id(new PHUIButtonView())
        ->setTag('a')
        ->addSigil('reveal-content')
        ->setStyle($show_style)
        ->setHref('#')
        ->setID($show_action_id)
        ->setMetaData(
          array(
            'hideIDs' => array($content_id, $show_action_id),
            'showIDs' => array($hide_action_id),
          ))
        ->setText($this->hideAction);

      $header->addActionLink($hide_action);
      $header->addActionLink($show_action);

      $showhide = array(
        phutil_tag(
          'div',
          array(
            'class' => 'phui-object-box-hidden-content',
            'id' => $content_id,
            'style' => $show_style,
          ),
          $this->showHideContent),
      );
    }


    if ($this->actionListID) {
      $icon_id = celerity_generate_unique_node_id();
      $icon = id(new PHUIIconView())
        ->setIcon('fa-bars');
      $meta = array(
        'map' => array(
          $this->actionListID => 'phabricator-action-list-toggle',
          $icon_id => 'phuix-dropdown-open',
        ),
      );
      $mobile_menu = id(new PHUIButtonView())
        ->setTag('a')
        ->setText(pht('Actions'))
        ->setHref('#')
        ->setIcon($icon)
        ->addClass('phui-mobile-menu')
        ->setID($icon_id)
        ->addSigil('jx-toggle-class')
        ->setMetadata($meta);
      $header->addActionLink($mobile_menu);
    }

    $ex = $this->validationException;
    $exception_errors = null;
    if ($ex) {
      $messages = array();
      foreach ($ex->getErrors() as $error) {
        $messages[] = $error->getMessage();
      }
      if ($messages) {
        $exception_errors = id(new PHUIInfoView())
          ->setErrors($messages);
      }
    }

    $tabs = null;
    if ($this->tabs) {
      $tabs = id(new PHUIListView())
        ->setType(PHUIListView::NAVBAR_LIST);
      foreach ($this->tabs as $tab) {
        $tabs->addMenuItem($tab);
      }
      Javelin::initBehavior('phui-object-box-tabs');
    }

    $content = array(
      ($this->showHideOpen == false ? $this->anchor : null),
      $header,
      $this->infoView,
      $this->formErrors,
      $this->formSaved,
      $exception_errors,
      $this->form,
      $tabs,
      $this->tabLists,
      $showhide,
      ($this->showHideOpen == true ? $this->anchor : null),
      $this->propertyList,
      $this->table,
      $this->renderChildren(),
    );

    if ($this->objectList) {
      $content[] = $this->objectList;
    }

    return $content;
  }
}
