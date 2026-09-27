<?php

namespace moca\sitebuilder\migrations;

use craft\db\Migration;
use moca\sitebuilder\records\BuiltSite;

/**
 * Creates the built sites table.
 *
 * @author Moca
 * @since 0.1.0
 */
class Install extends Migration
{
	// Public Methods
	// =========================================================================

	/**
	 * @inheritdoc
	 */
	public function safeUp(): bool
	{
		$this->createTable(BuiltSite::TABLE, [
			'id' => $this->primaryKey(),
			'siteId' => $this->integer()->notNull(),
			'theme' => $this->string()->notNull(),
			'volumeId' => $this->integer()->notNull(),
			'userGroupId' => $this->integer()->notNull(),
			'userId' => $this->integer()->notNull(),
			'schemaId' => $this->integer()->notNull(),
			'schemaName' => $this->string()->notNull(),
			'entryId' => $this->integer(),
			'details' => $this->text(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);
		$this->createIndex(null, BuiltSite::TABLE, ['siteId'], true);
		$this->addForeignKey(null, BuiltSite::TABLE, ['siteId'], '{{%sites}}', ['id'], 'CASCADE');
		$this->addForeignKey(null, BuiltSite::TABLE, ['userGroupId'], '{{%usergroups}}', ['id'], 'CASCADE');
		$this->addForeignKey(null, BuiltSite::TABLE, ['entryId'], '{{%elements}}', ['id'], 'SET NULL');

		return true;
	}

	/**
	 * @inheritdoc
	 */
	public function safeDown(): bool
	{
		$this->dropTableIfExists(BuiltSite::TABLE);

		return true;
	}
}
