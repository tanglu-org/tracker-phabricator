<?php

final class PhabricatorEmailVarySubjectsSetting
  extends PhabricatorSelectSetting {

  const SETTINGKEY = 'vary-subjects';

  const VALUE_VARY_SUBJECTS = 'true';
  const VALUE_STATIC_SUBJECTS = 'false';

  public function getSettingName() {
    return pht('Vary Subjects');
  }

  protected function getControlInstructions() {
    return pht(
      'With **Vary Subjects** enabled, most mail subject lines will include '.
      'a brief description of their content, like `[Closed]` for a '.
      'notification about someone closing a task.'.
      "\n\n".
      "| Setting              | Example Mail Subject\n".
      "|----------------------|----------------\n".
      "| Vary Subjects        | ".
      "`[Maniphest] [Closed] T123: Example Task`\n".
      "| Do Not Vary Subjects | ".
      "`[Maniphest] T123: Example Task`\n".
      "\n".
      'This can make mail more useful, but some clients have difficulty '.
      'threading these messages. Disabling this option may improve '.
      'threading at the cost of making subject lines less useful.');
  }

  public function getSettingDefaultValue() {
    return self::VALUE_VARY_SUBJECTS;
  }

  protected function getSelectOptions() {
    return array(
      self::VALUE_VARY_SUBJECTS => pht('Enable "Re:" Prefix'),
      self::VALUE_STATIC_SUBJECTS => pht('Disable "Re:" Prefix'),
    );
  }

}
