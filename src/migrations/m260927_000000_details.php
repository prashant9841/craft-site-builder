<?php

namespace moca\sitebuilder\migrations;

use craft\db\Migration;
use moca\sitebuilder\records\BuiltSite;

/**
 * Adds the setup details column.
 *
 * @author Moca
 * @since 0.1.1
 */
class m260927_000000_details extends Migration
{
	// Public Methods
	// =========================================================================

	/**
	 * @inheritdoc
	 */
	public function safeUp(): bool
	{
		if (!$this->db->columnExists(BuiltSite::TABLE, 'details')) {
			$this->addColumn(BuiltSite::TABLE, 'details', $this->text()->after('entryId'));
		}

		return true;
	}

	/**
	 * @inheritdoc
	 */
	public function safeDown(): bool
	{
		$this->dropColumn(BuiltSite::TABLE, 'details');

		return true;
	}
}
