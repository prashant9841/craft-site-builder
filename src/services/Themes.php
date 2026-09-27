<?php

namespace moca\sitebuilder\services;

use yii\base\Component;

/**
 * The themes a site can be built with. Each theme has a Home entry type (`home_<handle>`,
 * used by the site's Home single) and a page entry type (`pagebuilder_<handle>`, in the
 * shared `pagebuilder` section).
 *
 * Hard-coded for the proof of concept; themes will move to a JSON file.
 *
 * @author Moca
 * @since 0.1.0
 */
class Themes extends Component
{
	// Const Properties
	// =========================================================================

	/**
	 * Theme handle => name.
	 */
	public const THEMES = [
		'themeA' => 'Theme A',
		'themeB' => 'Theme B',
		'themeC' => 'Theme C',
		'themeD' => 'Theme D',
		'themeE' => 'Theme E',
	];

	// Public Methods
	// =========================================================================

	/**
	 * Returns every theme.
	 *
	 * @return array<string, array{handle: string, name: string, home: string, page: string}>
	 */
	public function getAllThemes(): array
	{
		$themes = [];
		foreach (self::THEMES as $handle => $name) {
			$themes[$handle] = [
				'handle' => $handle,
				'name' => $name,
				'home' => "home_{$handle}",
				'page' => "pagebuilder_{$handle}",
			];
		}

		return $themes;
	}

	/**
	 * Returns a theme by its handle.
	 *
	 * @param string $handle
	 * @return array{handle: string, name: string, home: string, page: string}|null
	 */
	public function getThemeByHandle(string $handle): ?array
	{
		return $this->getAllThemes()[$handle] ?? null;
	}
}
