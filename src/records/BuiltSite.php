<?php

namespace moca\sitebuilder\records;

use craft\db\ActiveRecord;

/**
 * A site built by the wizard, and what was created for it.
 *
 * @property int $id
 * @property int $siteId
 * @property string $theme
 * @property int $volumeId
 * @property int $userGroupId
 * @property int $userId
 * @property int $schemaId
 * @property string $schemaName
 * @property int|null $entryId The site's entry in the Sites Published section
 * @property string|null $details Setup details (Markdown)
 *
 * @author Moca
 * @since 0.1.0
 */
class BuiltSite extends ActiveRecord
{
	// Const Properties
	// =========================================================================

	/**
	 * Table name.
	 */
	public const TABLE = '{{%sitebuilder_sites}}';

	// Public Methods
	// =========================================================================

	/**
	 * @inheritdoc
	 */
	public static function tableName(): string
	{
		return self::TABLE;
	}
}
