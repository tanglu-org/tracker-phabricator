<?php

final class PhabricatorCalendarEventTransaction
  extends PhabricatorApplicationTransaction {

  const TYPE_NAME = 'calendar.name';
  const TYPE_START_DATE = 'calendar.startdate';
  const TYPE_END_DATE = 'calendar.enddate';
  const TYPE_DESCRIPTION = 'calendar.description';
  const TYPE_CANCEL = 'calendar.cancel';
  const TYPE_ALL_DAY = 'calendar.allday';
  const TYPE_ICON = 'calendar.icon';
  const TYPE_INVITE = 'calendar.invite';

  const TYPE_RECURRING = 'calendar.recurring';
  const TYPE_FREQUENCY = 'calendar.frequency';
  const TYPE_RECURRENCE_END_DATE = 'calendar.recurrenceenddate';

  const TYPE_INSTANCE_OF_EVENT = 'calendar.instanceofevent';
  const TYPE_SEQUENCE_INDEX = 'calendar.sequenceindex';

  const MAILTAG_RESCHEDULE = 'calendar-reschedule';
  const MAILTAG_CONTENT = 'calendar-content';
  const MAILTAG_OTHER = 'calendar-other';

  public function getApplicationName() {
    return 'calendar';
  }

  public function getApplicationTransactionType() {
    return PhabricatorCalendarEventPHIDType::TYPECONST;
  }

  public function getApplicationTransactionCommentObject() {
    return new PhabricatorCalendarEventTransactionComment();
  }

  public function getRequiredHandlePHIDs() {
    $phids = parent::getRequiredHandlePHIDs();

    switch ($this->getTransactionType()) {
      case self::TYPE_NAME:
      case self::TYPE_START_DATE:
      case self::TYPE_END_DATE:
      case self::TYPE_DESCRIPTION:
      case self::TYPE_CANCEL:
      case self::TYPE_ALL_DAY:
      case self::TYPE_RECURRING:
      case self::TYPE_FREQUENCY:
      case self::TYPE_RECURRENCE_END_DATE:
      case self::TYPE_INSTANCE_OF_EVENT:
      case self::TYPE_SEQUENCE_INDEX:
        $phids[] = $this->getObjectPHID();
        break;
      case self::TYPE_INVITE:
        $new = $this->getNewValue();
        foreach ($new as $phid => $status) {
          $phids[] = $phid;
        }
        break;
    }

    return $phids;
  }

  public function shouldHide() {
    $old = $this->getOldValue();
    switch ($this->getTransactionType()) {
      case self::TYPE_START_DATE:
      case self::TYPE_END_DATE:
      case self::TYPE_DESCRIPTION:
      case self::TYPE_CANCEL:
      case self::TYPE_ALL_DAY:
      case self::TYPE_INVITE:
      case self::TYPE_RECURRING:
      case self::TYPE_FREQUENCY:
      case self::TYPE_RECURRENCE_END_DATE:
      case self::TYPE_INSTANCE_OF_EVENT:
      case self::TYPE_SEQUENCE_INDEX:
        return ($old === null);
    }
    return parent::shouldHide();
  }

  public function getIcon() {
    switch ($this->getTransactionType()) {
      case self::TYPE_ICON:
        return $this->getNewValue();
      case self::TYPE_NAME:
      case self::TYPE_START_DATE:
      case self::TYPE_END_DATE:
      case self::TYPE_DESCRIPTION:
      case self::TYPE_ALL_DAY:
      case self::TYPE_CANCEL:
      case self::TYPE_RECURRING:
      case self::TYPE_FREQUENCY:
      case self::TYPE_RECURRENCE_END_DATE:
      case self::TYPE_INSTANCE_OF_EVENT:
      case self::TYPE_SEQUENCE_INDEX:
        return 'fa-pencil';
        break;
      case self::TYPE_INVITE:
        return 'fa-user-plus';
        break;
    }
    return parent::getIcon();
  }

  public function getTitle() {
    $author_phid = $this->getAuthorPHID();
    $object_phid = $this->getObjectPHID();

    $old = $this->getOldValue();
    $new = $this->getNewValue();

    $type = $this->getTransactionType();
    switch ($type) {
      case self::TYPE_NAME:
        if ($old === null) {
          return pht(
            '%s created this event.',
            $this->renderHandleLink($author_phid));
        } else {
          return pht(
            '%s changed the name of this event from %s to %s.',
            $this->renderHandleLink($author_phid),
            $old,
            $new);
        }
      case self::TYPE_START_DATE:
        if ($old) {
          return pht(
            '%s edited the start date of this event.',
            $this->renderHandleLink($author_phid));
        }
        break;
      case self::TYPE_END_DATE:
        if ($old) {
          return pht(
            '%s edited the end date of this event.',
            $this->renderHandleLink($author_phid));
        }
        break;
      case self::TYPE_DESCRIPTION:
        return pht(
          "%s updated the event's description.",
          $this->renderHandleLink($author_phid));
      case self::TYPE_ALL_DAY:
        if ($new) {
          return pht(
            '%s made this an all day event.',
            $this->renderHandleLink($author_phid));
        } else {
          return pht(
            '%s converted this from an all day event.',
            $this->renderHandleLink($author_phid));
        }
      case self::TYPE_ICON:
        $set = new PhabricatorCalendarIconSet();
        return pht(
          '%s set this event\'s icon to %s.',
          $this->renderHandleLink($author_phid),
          $set->getIconLabel($new));
        break;
      case self::TYPE_CANCEL:
        if ($new) {
          return pht(
            '%s cancelled this event.',
            $this->renderHandleLink($author_phid));
        } else {
          return pht(
            '%s reinstated this event.',
            $this->renderHandleLink($author_phid));
        }
      case self::TYPE_INVITE:
        $text = null;

        if (count($old) === 1
          && count($new) === 1
          && isset($old[$author_phid])) {
          // user joined/declined/accepted event themself
          $old_status = $old[$author_phid];
          $new_status = $new[$author_phid];

          if ($old_status !== $new_status) {
            switch ($new_status) {
              case PhabricatorCalendarEventInvitee::STATUS_INVITED:
                $text = pht(
                  '%s has joined this event.',
                  $this->renderHandleLink($author_phid));
                  break;
              case PhabricatorCalendarEventInvitee::STATUS_ATTENDING:
                $text = pht(
                  '%s is attending this event.',
                  $this->renderHandleLink($author_phid));
                  break;
              case PhabricatorCalendarEventInvitee::STATUS_DECLINED:
              case PhabricatorCalendarEventInvitee::STATUS_UNINVITED:
                $text = pht(
                  '%s has declined this event.',
                  $this->renderHandleLink($author_phid));
                  break;
              default:
                $text = pht(
                  '%s has changed their status for this event.',
                  $this->renderHandleLink($author_phid));
                break;
            }
          }
        } else {
          // user changed status for many users
          $self_joined = null;
          $self_declined = null;
          $added = array();
          $uninvited = array();

          foreach ($new as $phid => $status) {
            if ($status == PhabricatorCalendarEventInvitee::STATUS_INVITED
              || $status == PhabricatorCalendarEventInvitee::STATUS_ATTENDING) {
              // added users
              $added[] = $phid;
            } else if (
              $status == PhabricatorCalendarEventInvitee::STATUS_DECLINED
              || $status == PhabricatorCalendarEventInvitee::STATUS_UNINVITED) {
              $uninvited[] = $phid;
            }
          }

          $count_added = count($added);
          $count_uninvited = count($uninvited);
          $added_text = null;
          $uninvited_text = null;

          if ($count_added > 0 && $count_uninvited == 0) {
            $added_text = $this->renderHandleList($added);
            $text = pht('%s invited %s.',
              $this->renderHandleLink($author_phid),
              $added_text);
          } else if ($count_added > 0 && $count_uninvited > 0) {
            $added_text = $this->renderHandleList($added);
            $uninvited_text = $this->renderHandleList($uninvited);
            $text = pht('%s invited %s and uninvited %s.',
              $this->renderHandleLink($author_phid),
              $added_text,
              $uninvited_text);
          } else if ($count_added == 0 && $count_uninvited > 0) {
            $uninvited_text = $this->renderHandleList($uninvited);
            $text = pht('%s uninvited %s.',
              $this->renderHandleLink($author_phid),
              $uninvited_text);
          } else {
            $text = pht('%s updated the invitee list.',
              $this->renderHandleLink($author_phid));
          }
        }
        return $text;
      case self::TYPE_RECURRING:
        $text = pht('%s made this event recurring.',
          $this->renderHandleLink($author_phid));
        return $text;
      case self::TYPE_FREQUENCY:
        $text = '';
        switch ($new['rule']) {
          case PhabricatorCalendarEvent::FREQUENCY_DAILY:
            $text = pht('%s set this event to repeat daily.',
              $this->renderHandleLink($author_phid));
            break;
          case PhabricatorCalendarEvent::FREQUENCY_WEEKLY:
            $text = pht('%s set this event to repeat weekly.',
              $this->renderHandleLink($author_phid));
            break;
          case PhabricatorCalendarEvent::FREQUENCY_MONTHLY:
            $text = pht('%s set this event to repeat monthly.',
              $this->renderHandleLink($author_phid));
            break;
          case PhabricatorCalendarEvent::FREQUENCY_YEARLY:
            $text = pht('%s set this event to repeat yearly.',
              $this->renderHandleLink($author_phid));
            break;
        }
        return $text;
      case self::TYPE_RECURRENCE_END_DATE:
        $text = pht('%s has changed the recurrence end date of this event.',
          $this->renderHandleLink($author_phid));
        return $text;
      case self::TYPE_INSTANCE_OF_EVENT:
      case self::TYPE_SEQUENCE_INDEX:
        return pht('Recurring event has been updated.');
    }
    return parent::getTitle();
  }

  public function getTitleForFeed() {
    $author_phid = $this->getAuthorPHID();
    $object_phid = $this->getObjectPHID();

    $old = $this->getOldValue();
    $new = $this->getNewValue();

    $viewer = $this->getViewer();

    $type = $this->getTransactionType();
    switch ($type) {
      case self::TYPE_NAME:
        if ($old === null) {
          return pht(
            '%s created %s.',
            $this->renderHandleLink($author_phid),
            $this->renderHandleLink($object_phid));
        } else {
          return pht(
            '%s changed the name of %s from %s to %s.',
            $this->renderHandleLink($author_phid),
            $this->renderHandleLink($object_phid),
            $old,
            $new);
        }
      case self::TYPE_START_DATE:
        if ($old) {
          $old = phabricator_datetime($old, $viewer);
          $new = phabricator_datetime($new, $viewer);
          return pht(
            '%s changed the start date of %s from %s to %s.',
            $this->renderHandleLink($author_phid),
            $this->renderHandleLink($object_phid),
            $old,
            $new);
        }
        break;
      case self::TYPE_END_DATE:
        if ($old) {
          $old = phabricator_datetime($old, $viewer);
          $new = phabricator_datetime($new, $viewer);
          return pht(
            '%s edited the end date of %s from %s to %s.',
            $this->renderHandleLink($author_phid),
            $this->renderHandleLink($object_phid),
            $old,
            $new);
        }
        break;
      case self::TYPE_DESCRIPTION:
        return pht(
          '%s updated the description of %s.',
          $this->renderHandleLink($author_phid),
          $this->renderHandleLink($object_phid));
      case self::TYPE_ALL_DAY:
        if ($new) {
          return pht(
            '%s made %s an all day event.',
            $this->renderHandleLink($author_phid),
            $this->renderHandleLink($object_phid));
        } else {
          return pht(
            '%s converted %s from an all day event.',
            $this->renderHandleLink($author_phid),
            $this->renderHandleLink($object_phid));
        }
      case self::TYPE_ICON:
        $set = new PhabricatorCalendarIconSet();
        return pht(
          '%s set the icon for %s to %s.',
          $this->renderHandleLink($author_phid),
          $this->renderHandleLink($object_phid),
          $set->getIconLabel($new));
      case self::TYPE_CANCEL:
        if ($new) {
          return pht(
            '%s cancelled %s.',
            $this->renderHandleLink($author_phid),
            $this->renderHandleLink($object_phid));
        } else {
          return pht(
            '%s reinstated %s.',
            $this->renderHandleLink($author_phid),
            $this->renderHandleLink($object_phid));
        }
      case self::TYPE_INVITE:
        $text = null;

        if (count($old) === 1
          && count($new) === 1
          && isset($old[$author_phid])) {
          // user joined/declined/accepted event themself
          $old_status = $old[$author_phid];
          $new_status = $new[$author_phid];

          if ($old_status !== $new_status) {
            switch ($new_status) {
              case PhabricatorCalendarEventInvitee::STATUS_INVITED:
                $text = pht(
                  '%s has joined %s.',
                  $this->renderHandleLink($author_phid),
                  $this->renderHandleLink($object_phid));
                  break;
              case PhabricatorCalendarEventInvitee::STATUS_ATTENDING:
                $text = pht(
                  '%s is attending %s.',
                  $this->renderHandleLink($author_phid),
                  $this->renderHandleLink($object_phid));
                  break;
              case PhabricatorCalendarEventInvitee::STATUS_DECLINED:
              case PhabricatorCalendarEventInvitee::STATUS_UNINVITED:
                $text = pht(
                  '%s has declined %s.',
                  $this->renderHandleLink($author_phid),
                  $this->renderHandleLink($object_phid));
                  break;
              default:
                $text = pht(
                  '%s has changed their status of %s.',
                  $this->renderHandleLink($author_phid),
                  $this->renderHandleLink($object_phid));
                break;
            }
          }
        } else {
          // user changed status for many users
          $self_joined = null;
          $self_declined = null;
          $added = array();
          $uninvited = array();

          foreach ($new as $phid => $status) {
            if ($status == PhabricatorCalendarEventInvitee::STATUS_INVITED
              || $status == PhabricatorCalendarEventInvitee::STATUS_ATTENDING) {
              // added users
              $added[] = $phid;
            } else if (
              $status == PhabricatorCalendarEventInvitee::STATUS_DECLINED
              || $status == PhabricatorCalendarEventInvitee::STATUS_UNINVITED) {
              $uninvited[] = $phid;
            }
          }

          $count_added = count($added);
          $count_uninvited = count($uninvited);
          $added_text = null;
          $uninvited_text = null;

          if ($count_added > 0 && $count_uninvited == 0) {
            $added_text = $this->renderHandleList($added);
            $text = pht('%s invited %s to %s.',
              $this->renderHandleLink($author_phid),
              $added_text,
              $this->renderHandleLink($object_phid));
          } else if ($count_added > 0 && $count_uninvited > 0) {
            $added_text = $this->renderHandleList($added);
            $uninvited_text = $this->renderHandleList($uninvited);
            $text = pht('%s invited %s and uninvited %s to %s.',
              $this->renderHandleLink($author_phid),
              $added_text,
              $uninvited_text,
              $this->renderHandleLink($object_phid));
          } else if ($count_added == 0 && $count_uninvited > 0) {
            $uninvited_text = $this->renderHandleList($uninvited);
            $text = pht('%s uninvited %s to %s.',
              $this->renderHandleLink($author_phid),
              $uninvited_text,
              $this->renderHandleLink($object_phid));
          } else {
            $text = pht('%s updated the invitee list of %s.',
              $this->renderHandleLink($author_phid),
              $this->renderHandleLink($object_phid));
          }
        }
        return $text;
      case self::TYPE_RECURRING:
        $text = pht('%s made %s a recurring event.',
          $this->renderHandleLink($author_phid),
          $this->renderHandleLink($object_phid));
        return $text;
      case self::TYPE_FREQUENCY:
        $text = '';
        switch ($new['rule']) {
          case PhabricatorCalendarEvent::FREQUENCY_DAILY:
            $text = pht('%s set %s to repeat daily.',
              $this->renderHandleLink($author_phid),
              $this->renderHandleLink($object_phid));
            break;
          case PhabricatorCalendarEvent::FREQUENCY_WEEKLY:
            $text = pht('%s set %s to repeat weekly.',
              $this->renderHandleLink($author_phid),
              $this->renderHandleLink($object_phid));
            break;
          case PhabricatorCalendarEvent::FREQUENCY_MONTHLY:
            $text = pht('%s set %s to repeat monthly.',
              $this->renderHandleLink($author_phid),
              $this->renderHandleLink($object_phid));
            break;
          case PhabricatorCalendarEvent::FREQUENCY_YEARLY:
            $text = pht('%s set %s to repeat yearly.',
              $this->renderHandleLink($author_phid),
              $this->renderHandleLink($object_phid));
            break;
        }
        return $text;
      case self::TYPE_RECURRENCE_END_DATE:
        $text = pht('%s set the recurrence end date of %s to %s.',
          $this->renderHandleLink($author_phid),
          $this->renderHandleLink($object_phid),
          $new);
        return $text;
      case self::TYPE_INSTANCE_OF_EVENT:
      case self::TYPE_SEQUENCE_INDEX:
        return pht('Recurring event has been updated.');
    }

    return parent::getTitleForFeed();
  }

  public function getColor() {
    $old = $this->getOldValue();
    $new = $this->getNewValue();

    switch ($this->getTransactionType()) {
      case self::TYPE_NAME:
      case self::TYPE_START_DATE:
      case self::TYPE_END_DATE:
      case self::TYPE_DESCRIPTION:
      case self::TYPE_CANCEL:
      case self::TYPE_INVITE:
        return PhabricatorTransactions::COLOR_GREEN;
    }

    return parent::getColor();
  }


  public function hasChangeDetails() {
    switch ($this->getTransactionType()) {
      case self::TYPE_DESCRIPTION:
        return ($this->getOldValue() !== null);
    }

    return parent::hasChangeDetails();
  }

  public function renderChangeDetails(PhabricatorUser $viewer) {
    switch ($this->getTransactionType()) {
      case self::TYPE_DESCRIPTION:
        $old = $this->getOldValue();
        $new = $this->getNewValue();

        return $this->renderTextCorpusChangeDetails(
          $viewer,
          $old,
          $new);
    }

    return parent::renderChangeDetails($viewer);
  }

  public function getMailTags() {
    $tags = array();
    switch ($this->getTransactionType()) {
      case self::TYPE_NAME:
      case self::TYPE_DESCRIPTION:
      case self::TYPE_INVITE:
      case self::TYPE_ICON:
        $tags[] = self::MAILTAG_CONTENT;
        break;
      case self::TYPE_START_DATE:
      case self::TYPE_END_DATE:
      case self::TYPE_CANCEL:
        $tags[] = self::MAILTAG_RESCHEDULE;
        break;
    }
    return $tags;
  }

}
