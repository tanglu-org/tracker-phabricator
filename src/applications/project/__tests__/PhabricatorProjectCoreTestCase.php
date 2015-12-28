<?php

final class PhabricatorProjectCoreTestCase extends PhabricatorTestCase {

  protected function getPhabricatorTestCaseConfiguration() {
    return array(
      self::PHABRICATOR_TESTCONFIG_BUILD_STORAGE_FIXTURES => true,
    );
  }

  public function testViewProject() {
    $user = $this->createUser();
    $user->save();

    $user2 = $this->createUser();
    $user2->save();

    $proj = $this->createProject($user);

    $proj = $this->refreshProject($proj, $user, true);

    $this->joinProject($proj, $user);
    $proj->setViewPolicy(PhabricatorPolicies::POLICY_USER);
    $proj->save();

    $can_view = PhabricatorPolicyCapability::CAN_VIEW;

    // When the view policy is set to "users", any user can see the project.
    $this->assertTrue((bool)$this->refreshProject($proj, $user));
    $this->assertTrue((bool)$this->refreshProject($proj, $user2));


    // When the view policy is set to "no one", members can still see the
    // project.
    $proj->setViewPolicy(PhabricatorPolicies::POLICY_NOONE);
    $proj->save();

    $this->assertTrue((bool)$this->refreshProject($proj, $user));
    $this->assertFalse((bool)$this->refreshProject($proj, $user2));
  }

  public function testIsViewerMemberOrWatcher() {
    $user1 = $this->createUser()
      ->save();

    $user2 = $this->createUser()
      ->save();

    $user3 = $this->createUser()
      ->save();

    $proj1 = $this->createProject($user1);
    $proj1 = $this->refreshProject($proj1, $user1);

    $this->joinProject($proj1, $user1);
    $this->joinProject($proj1, $user3);
    $this->watchProject($proj1, $user3);

    $proj1 = $this->refreshProject($proj1, $user1);

    $this->assertTrue($proj1->isUserMember($user1->getPHID()));

    $proj1 = $this->refreshProject($proj1, $user1, false, true);

    $this->assertTrue($proj1->isUserMember($user1->getPHID()));
    $this->assertFalse($proj1->isUserWatcher($user1->getPHID()));

    $proj1 = $this->refreshProject($proj1, $user1, true, false);

    $this->assertTrue($proj1->isUserMember($user1->getPHID()));
    $this->assertFalse($proj1->isUserMember($user2->getPHID()));
    $this->assertTrue($proj1->isUserMember($user3->getPHID()));

    $proj1 = $this->refreshProject($proj1, $user1, true, true);

    $this->assertTrue($proj1->isUserMember($user1->getPHID()));
    $this->assertFalse($proj1->isUserMember($user2->getPHID()));
    $this->assertTrue($proj1->isUserMember($user3->getPHID()));

    $this->assertFalse($proj1->isUserWatcher($user1->getPHID()));
    $this->assertFalse($proj1->isUserWatcher($user2->getPHID()));
    $this->assertTrue($proj1->isUserWatcher($user3->getPHID()));
  }

  public function testEditProject() {
    $user = $this->createUser();
    $user->save();

    $user2 = $this->createUser();
    $user2->save();

    $proj = $this->createProject($user);


    // When edit and view policies are set to "user", anyone can edit.
    $proj->setViewPolicy(PhabricatorPolicies::POLICY_USER);
    $proj->setEditPolicy(PhabricatorPolicies::POLICY_USER);
    $proj->save();

    $this->assertTrue($this->attemptProjectEdit($proj, $user));


    // When edit policy is set to "no one", no one can edit.
    $proj->setEditPolicy(PhabricatorPolicies::POLICY_NOONE);
    $proj->save();

    $caught = null;
    try {
      $this->attemptProjectEdit($proj, $user);
    } catch (Exception $ex) {
      $caught = $ex;
    }
    $this->assertTrue($caught instanceof Exception);
  }

  public function testAncestryQueries() {
    $user = $this->createUser();
    $user->save();

    $ancestor = $this->createProject($user);
    $parent = $this->createProject($user, $ancestor);
    $child = $this->createProject($user, $parent);

    $projects = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withAncestorProjectPHIDs(array($ancestor->getPHID()))
      ->execute();
    $this->assertEqual(2, count($projects));

    $projects = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withParentProjectPHIDs(array($ancestor->getPHID()))
      ->execute();
    $this->assertEqual(1, count($projects));
    $this->assertEqual(
      $parent->getPHID(),
      head($projects)->getPHID());

    $projects = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withAncestorProjectPHIDs(array($ancestor->getPHID()))
      ->withDepthBetween(2, null)
      ->execute();
    $this->assertEqual(1, count($projects));
    $this->assertEqual(
      $child->getPHID(),
      head($projects)->getPHID());

    $parent2 = $this->createProject($user, $ancestor);
    $child2 = $this->createProject($user, $parent2);
    $grandchild2 = $this->createProject($user, $child2);

    $projects = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withAncestorProjectPHIDs(array($ancestor->getPHID()))
      ->execute();
    $this->assertEqual(5, count($projects));

    $projects = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withParentProjectPHIDs(array($ancestor->getPHID()))
      ->execute();
    $this->assertEqual(2, count($projects));

    $projects = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withAncestorProjectPHIDs(array($ancestor->getPHID()))
      ->withDepthBetween(2, null)
      ->execute();
    $this->assertEqual(3, count($projects));

    $projects = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withAncestorProjectPHIDs(array($ancestor->getPHID()))
      ->withDepthBetween(3, null)
      ->execute();
    $this->assertEqual(1, count($projects));

    $projects = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withPHIDs(
        array(
          $child->getPHID(),
          $grandchild2->getPHID(),
        ))
      ->execute();
    $this->assertEqual(2, count($projects));
  }

  public function testMemberMaterialization() {
    $material_type = PhabricatorProjectMaterializedMemberEdgeType::EDGECONST;

    $user = $this->createUser();
    $user->save();

    $parent = $this->createProject($user);
    $child = $this->createProject($user, $parent);

    $this->joinProject($child, $user);

    $parent_material = PhabricatorEdgeQuery::loadDestinationPHIDs(
      $parent->getPHID(),
      $material_type);

    $this->assertEqual(
      array($user->getPHID()),
      $parent_material);
  }

  public function testMilestones() {
    $user = $this->createUser();
    $user->save();

    $parent = $this->createProject($user);

    $m1 = $this->createProject($user, $parent, true);
    $m2 = $this->createProject($user, $parent, true);
    $m3 = $this->createProject($user, $parent, true);

    $this->assertEqual(1, $m1->getMilestoneNumber());
    $this->assertEqual(2, $m2->getMilestoneNumber());
    $this->assertEqual(3, $m3->getMilestoneNumber());
  }

  public function testMilestoneMembership() {
    $user = $this->createUser();
    $user->save();

    $parent = $this->createProject($user);
    $milestone = $this->createProject($user, $parent, true);

    $this->joinProject($parent, $user);

    $milestone = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withPHIDs(array($milestone->getPHID()))
      ->executeOne();

    $this->assertTrue($milestone->isUserMember($user->getPHID()));

    $milestone = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withPHIDs(array($milestone->getPHID()))
      ->needMembers(true)
      ->executeOne();

    $this->assertEqual(
      array($user->getPHID()),
      $milestone->getMemberPHIDs());
  }

  public function testSameSlugAsName() {
    // It should be OK to type the primary hashtag into "additional hashtags",
    // even if the primary hashtag doesn't exist yet because you're creating
    // or renaming the project.

    $user = $this->createUser();
    $user->save();

    $project = $this->createProject($user);

    // In this first case, set the name and slugs at the same time.
    $name = 'slugproject';

    $xactions = array();
    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorProjectTransaction::TYPE_NAME)
      ->setNewValue($name);
    $this->applyTransactions($project, $user, $xactions);

    $xactions = array();
    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorProjectTransaction::TYPE_SLUGS)
      ->setNewValue(array($name));
    $this->applyTransactions($project, $user, $xactions);

    $project = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withPHIDs(array($project->getPHID()))
      ->needSlugs(true)
      ->executeOne();

    $slugs = $project->getSlugs();
    $slugs = mpull($slugs, 'getSlug');

    $this->assertTrue(in_array($name, $slugs));

    // In this second case, set the name first and then the slugs separately.
    $name2 = 'slugproject2';
    $xactions = array();

    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorProjectTransaction::TYPE_NAME)
      ->setNewValue($name2);

    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorProjectTransaction::TYPE_SLUGS)
      ->setNewValue(array($name2));

    $this->applyTransactions($project, $user, $xactions);

    $project = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withPHIDs(array($project->getPHID()))
      ->needSlugs(true)
      ->executeOne();

    $slugs = $project->getSlugs();
    $slugs = mpull($slugs, 'getSlug');

    $this->assertTrue(in_array($name2, $slugs));
  }

  public function testDuplicateSlugs() {
    // Creating a project with multiple duplicate slugs should succeed.

    $user = $this->createUser();
    $user->save();

    $project = $this->createProject($user);

    $input = 'duplicate';

    $xactions = array();

    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorProjectTransaction::TYPE_SLUGS)
      ->setNewValue(array($input, $input));

    $this->applyTransactions($project, $user, $xactions);

    $project = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withPHIDs(array($project->getPHID()))
      ->needSlugs(true)
      ->executeOne();

    $slugs = $project->getSlugs();
    $slugs = mpull($slugs, 'getSlug');

    $this->assertTrue(in_array($input, $slugs));
  }

  public function testNormalizeSlugs() {
    // When a user creates a project with slug "XxX360n0sc0perXxX", normalize
    // it before writing it.

    $user = $this->createUser();
    $user->save();

    $project = $this->createProject($user);

    $input = 'NoRmAlIzE';
    $expect = 'normalize';

    $xactions = array();

    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorProjectTransaction::TYPE_SLUGS)
      ->setNewValue(array($input));

    $this->applyTransactions($project, $user, $xactions);

    $project = id(new PhabricatorProjectQuery())
      ->setViewer($user)
      ->withPHIDs(array($project->getPHID()))
      ->needSlugs(true)
      ->executeOne();

    $slugs = $project->getSlugs();
    $slugs = mpull($slugs, 'getSlug');

    $this->assertTrue(in_array($expect, $slugs));


    // If another user tries to add the same slug in denormalized form, it
    // should be caught and fail, even though the database version of the slug
    // is normalized.

    $project2 = $this->createProject($user);

    $xactions = array();

    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorProjectTransaction::TYPE_SLUGS)
      ->setNewValue(array($input));

    $caught = null;
    try {
      $this->applyTransactions($project2, $user, $xactions);
    } catch (PhabricatorApplicationTransactionValidationException $ex) {
      $caught = $ex;
    }

    $this->assertTrue((bool)$caught);
  }

  public function testProjectMembersVisibility() {
    // This is primarily testing that you can create a project and set the
    // visibility or edit policy to "Project Members" immediately.

    $user1 = $this->createUser();
    $user1->save();

    $user2 = $this->createUser();
    $user2->save();

    $project = PhabricatorProject::initializeNewProject($user1);
    $name = pht('Test Project %d', mt_rand());

    $xactions = array();

    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorProjectTransaction::TYPE_NAME)
      ->setNewValue($name);

    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_VIEW_POLICY)
      ->setNewValue(
        id(new PhabricatorProjectMembersPolicyRule())
          ->getObjectPolicyFullKey());

    $edge_type = PhabricatorProjectProjectHasMemberEdgeType::EDGECONST;

    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
      ->setMetadataValue('edge:type', $edge_type)
      ->setNewValue(
        array(
          '=' => array($user1->getPHID() => $user1->getPHID()),
        ));

    $this->applyTransactions($project, $user1, $xactions);

    $this->assertTrue((bool)$this->refreshProject($project, $user1));
    $this->assertFalse((bool)$this->refreshProject($project, $user2));

    $this->leaveProject($project, $user1);

    $this->assertFalse((bool)$this->refreshProject($project, $user1));
  }

  public function testParentProject() {
    $user = $this->createUser();
    $user->save();

    $parent = $this->createProject($user);
    $child = $this->createProject($user, $parent);

    $this->assertTrue(true);

    $child = $this->refreshProject($child, $user);

    $this->assertEqual(
      $parent->getPHID(),
      $child->getParentProject()->getPHID());

    $this->assertEqual(1, (int)$child->getProjectDepth());

    $this->assertFalse(
      $child->isUserMember($user->getPHID()));

    $this->assertFalse(
      $child->getParentProject()->isUserMember($user->getPHID()));

    $this->joinProject($child, $user);

    $child = $this->refreshProject($child, $user);

    $this->assertTrue(
      $child->isUserMember($user->getPHID()));

    $this->assertTrue(
      $child->getParentProject()->isUserMember($user->getPHID()));


    // Test that hiding a parent hides the child.

    $user2 = $this->createUser();
    $user2->save();

    // Second user can see the project for now.
    $this->assertTrue((bool)$this->refreshProject($child, $user2));

    // Hide the parent.
    $this->setViewPolicy($parent, $user, $user->getPHID());

    // First user (who can see the parent because they are a member of
    // the child) can see the project.
    $this->assertTrue((bool)$this->refreshProject($child, $user));

    // Second user can not, because they can't see the parent.
    $this->assertFalse((bool)$this->refreshProject($child, $user2));
  }

  private function attemptProjectEdit(
    PhabricatorProject $proj,
    PhabricatorUser $user,
    $skip_refresh = false) {

    $proj = $this->refreshProject($proj, $user, true);

    $new_name = $proj->getName().' '.mt_rand();

    $xaction = new PhabricatorProjectTransaction();
    $xaction->setTransactionType(PhabricatorProjectTransaction::TYPE_NAME);
    $xaction->setNewValue($new_name);

    $this->applyTransactions($proj, $user, array($xaction));

    return true;
  }

  public function testJoinLeaveProject() {
    $user = $this->createUser();
    $user->save();

    $proj = $this->createProjectWithNewAuthor();

    $proj = $this->refreshProject($proj, $user, true);
    $this->assertTrue(
      (bool)$proj,
      pht(
        'Assumption that projects are default visible '.
        'to any user when created.'));

    $this->assertFalse(
      $proj->isUserMember($user->getPHID()),
      pht('Arbitrary user not member of project.'));

    // Join the project.
    $this->joinProject($proj, $user);

    $proj = $this->refreshProject($proj, $user, true);
    $this->assertTrue((bool)$proj);

    $this->assertTrue(
      $proj->isUserMember($user->getPHID()),
      pht('Join works.'));


    // Join the project again.
    $this->joinProject($proj, $user);

    $proj = $this->refreshProject($proj, $user, true);
    $this->assertTrue((bool)$proj);

    $this->assertTrue(
      $proj->isUserMember($user->getPHID()),
      pht('Joining an already-joined project is a no-op.'));


    // Leave the project.
    $this->leaveProject($proj, $user);

    $proj = $this->refreshProject($proj, $user, true);
    $this->assertTrue((bool)$proj);

    $this->assertFalse(
      $proj->isUserMember($user->getPHID()),
      pht('Leave works.'));


    // Leave the project again.
    $this->leaveProject($proj, $user);

    $proj = $this->refreshProject($proj, $user, true);
    $this->assertTrue((bool)$proj);

    $this->assertFalse(
      $proj->isUserMember($user->getPHID()),
      pht('Leaving an already-left project is a no-op.'));


    // If a user can't edit or join a project, joining fails.
    $proj->setEditPolicy(PhabricatorPolicies::POLICY_NOONE);
    $proj->setJoinPolicy(PhabricatorPolicies::POLICY_NOONE);
    $proj->save();

    $proj = $this->refreshProject($proj, $user, true);
    $caught = null;
    try {
      $this->joinProject($proj, $user);
    } catch (Exception $ex) {
      $caught = $ex;
    }
    $this->assertTrue($ex instanceof Exception);


    // If a user can edit a project, they can join.
    $proj->setEditPolicy(PhabricatorPolicies::POLICY_USER);
    $proj->setJoinPolicy(PhabricatorPolicies::POLICY_NOONE);
    $proj->save();

    $proj = $this->refreshProject($proj, $user, true);
    $this->joinProject($proj, $user);
    $proj = $this->refreshProject($proj, $user, true);
    $this->assertTrue(
      $proj->isUserMember($user->getPHID()),
      pht('Join allowed with edit permission.'));
    $this->leaveProject($proj, $user);


    // If a user can join a project, they can join, even if they can't edit.
    $proj->setEditPolicy(PhabricatorPolicies::POLICY_NOONE);
    $proj->setJoinPolicy(PhabricatorPolicies::POLICY_USER);
    $proj->save();

    $proj = $this->refreshProject($proj, $user, true);
    $this->joinProject($proj, $user);
    $proj = $this->refreshProject($proj, $user, true);
    $this->assertTrue(
      $proj->isUserMember($user->getPHID()),
      pht('Join allowed with join permission.'));


    // A user can leave a project even if they can't edit it or join.
    $proj->setEditPolicy(PhabricatorPolicies::POLICY_NOONE);
    $proj->setJoinPolicy(PhabricatorPolicies::POLICY_NOONE);
    $proj->save();

    $proj = $this->refreshProject($proj, $user, true);
    $this->leaveProject($proj, $user);
    $proj = $this->refreshProject($proj, $user, true);
    $this->assertFalse(
      $proj->isUserMember($user->getPHID()),
      pht('Leave allowed without any permission.'));
  }

  private function refreshProject(
    PhabricatorProject $project,
    PhabricatorUser $viewer,
    $need_members = false,
    $need_watchers = false) {

    $results = id(new PhabricatorProjectQuery())
      ->setViewer($viewer)
      ->needMembers($need_members)
      ->needWatchers($need_watchers)
      ->withIDs(array($project->getID()))
      ->execute();

    if ($results) {
      return head($results);
    } else {
      return null;
    }
  }

  private function createProject(
    PhabricatorUser $user,
    PhabricatorProject $parent = null,
    $is_milestone = false) {

    $project = PhabricatorProject::initializeNewProject($user);


    $name = pht('Test Project %d', mt_rand());

    $xactions = array();

    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorProjectTransaction::TYPE_NAME)
      ->setNewValue($name);

    if ($parent) {
      $xactions[] = id(new PhabricatorProjectTransaction())
        ->setTransactionType(PhabricatorProjectTransaction::TYPE_PARENT)
        ->setNewValue($parent->getPHID());
    }

    if ($is_milestone) {
      $xactions[] = id(new PhabricatorProjectTransaction())
        ->setTransactionType(PhabricatorProjectTransaction::TYPE_MILESTONE)
        ->setNewValue(true);
    }

    $this->applyTransactions($project, $user, $xactions);

    return $project;
  }

  private function setViewPolicy(
    PhabricatorProject $project,
    PhabricatorUser $user,
    $policy) {

    $xactions = array();

    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_VIEW_POLICY)
      ->setNewValue($policy);

    $this->applyTransactions($project, $user, $xactions);

    return $project;
  }

  private function createProjectWithNewAuthor() {
    $author = $this->createUser();
    $author->save();

    $project = $this->createProject($author);

    return $project;
  }

  private function createUser() {
    $rand = mt_rand();

    $user = new PhabricatorUser();
    $user->setUsername('unittestuser'.$rand);
    $user->setRealName(pht('Unit Test User %d', $rand));

    return $user;
  }

  private function joinProject(
    PhabricatorProject $project,
    PhabricatorUser $user) {
    return $this->joinOrLeaveProject($project, $user, '+');
  }

  private function leaveProject(
    PhabricatorProject $project,
    PhabricatorUser $user) {
    return $this->joinOrLeaveProject($project, $user, '-');
  }

  private function watchProject(
    PhabricatorProject $project,
    PhabricatorUser $user) {
    return $this->watchOrUnwatchProject($project, $user, '+');
  }

  private function unwatchProject(
    PhabricatorProject $project,
    PhabricatorUser $user) {
    return $this->watchOrUnwatchProject($project, $user, '-');
  }

  private function joinOrLeaveProject(
    PhabricatorProject $project,
    PhabricatorUser $user,
    $operation) {
    return $this->applyProjectEdgeTransaction(
      $project,
      $user,
      $operation,
      PhabricatorProjectProjectHasMemberEdgeType::EDGECONST);
  }

  private function watchOrUnwatchProject(
    PhabricatorProject $project,
    PhabricatorUser $user,
    $operation) {
    return $this->applyProjectEdgeTransaction(
      $project,
      $user,
      $operation,
      PhabricatorObjectHasWatcherEdgeType::EDGECONST);
  }

  private function applyProjectEdgeTransaction(
    PhabricatorProject $project,
    PhabricatorUser $user,
    $operation,
    $edge_type) {

    $spec = array(
      $operation => array($user->getPHID() => $user->getPHID()),
    );

    $xactions = array();
    $xactions[] = id(new PhabricatorProjectTransaction())
      ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
      ->setMetadataValue('edge:type', $edge_type)
      ->setNewValue($spec);

    $this->applyTransactions($project, $user, $xactions);

    return $project;
  }

  private function applyTransactions(
    PhabricatorProject $project,
    PhabricatorUser $user,
    array $xactions) {

    $editor = id(new PhabricatorProjectTransactionEditor())
      ->setActor($user)
      ->setContentSource(PhabricatorContentSource::newConsoleSource())
      ->setContinueOnNoEffect(true)
      ->applyTransactions($project, $xactions);
  }


}
