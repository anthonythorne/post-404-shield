/**
 * Settings → Post 404 Shield, built from WordPress components.
 *
 * No build step: this file uses the `wp.*` modules WordPress core registers
 * (wp-element, wp-components, wp-i18n, wp-a11y) — the same modules a bundled
 * `@wordpress/*` import resolves to — so the mu-plugin ships as plain source.
 *
 * The screen still saves through an ordinary form POST to admin-post.php with
 * the field names the PHP handler has always read (`ps_types[…]`,
 * `ps_blocks[…]`, `ps_locale_mode`, …). The app keeps the form state and
 * renders those fields as hidden inputs, so validation, nonces, capability
 * checks and the root-mode acknowledgement all stay server-side.
 *
 * State comes from `window.postShieldAdmin`, printed by
 * PostShieldAdminController::enqueue_assets().
 *
 * File Path: wp-content/mu-plugins/post-404-shield/src/js/admin.js
 *
 * @param {Object} wp   WordPress script globals.
 * @param {Object} data Screen state from PHP.
 */

(function (wp, data) {
	'use strict';

	const root = document.getElementById('post-shield-admin-root');
	if (!wp || !wp.element || !wp.components || !data || !root) {
		return;
	}

	const {
		createElement: el,
		createRoot,
		Fragment,
		render,
		useEffect,
		useRef,
		useState,
	} = wp.element;
	const { __, _n, sprintf } = wp.i18n;
	const {
		Button,
		Card,
		CardBody,
		CardHeader,
		CheckboxControl,
		Flex,
		FlexItem,
		Modal,
		Notice,
		SelectControl,
		Spinner,
		TabPanel,
		TextareaControl,
		TextControl,
		ToggleControl,
	} = wp.components;
	const speak = wp.a11y && wp.a11y.speak ? wp.a11y.speak : () => {};

	const TAB_STORAGE_KEY = 'postShieldAdminTab';
	const CONFIG_TABS = ['types', 'root', 'blocked', 'site'];

	// ── Small helpers ──────────────────────────────────────────────────────

	const splitLines = (value) =>
		String(value || '')
			.split(/\r\n|\r|\n/)
			.map((line) => line.trim().replace(/^\/+|\/+$/g, ''))
			.filter(Boolean);

	const byLabel = (a, b) => a.label.localeCompare(b.label);

	const storage = {
		get() {
			try {
				return window.sessionStorage.getItem(TAB_STORAGE_KEY) || '';
			} catch (e) {
				return '';
			}
		},
		set(value) {
			try {
				window.sessionStorage.setItem(TAB_STORAGE_KEY, value);
			} catch (e) {
				// Storage blocked (private window, policy) — the tab just resets.
			}
		},
	};

	function Hidden({ name, value }) {
		return el('input', {
			type: 'hidden',
			name,
			value: null === value || undefined === value ? '' : String(value),
		});
	}

	function Chips({ items, muted }) {
		return el(
			'div',
			{ className: 'tcc-admin__chips' },
			items.map((item) =>
				el(
					'code',
					{
						key: item,
						className:
							'tcc-admin__chip' +
							(muted ? ' tcc-admin__chip--muted' : ''),
					},
					item
				)
			)
		);
	}

	function Badge({ tone, children }) {
		return el(
			'span',
			{
				className:
					'tcc-admin__badge' +
					(tone ? ' tcc-admin__badge--' + tone : ''),
			},
			children
		);
	}

	function SectionCard({ title, description, actions, children }) {
		return el(
			Card,
			{ className: 'post-shield-card', size: 'large' },
			el(
				CardHeader,
				null,
				el(
					Flex,
					{ align: 'flex-start', gap: 4, wrap: true },
					el(
						FlexItem,
						{ isBlock: true },
						el('h2', { className: 'tcc-admin__card-title' }, title),
						description
							? el(
									'p',
									{
										className:
											'tcc-admin__card-description',
									},
									description
								)
							: null
					),
					actions ? el(FlexItem, null, actions) : null
				)
			),
			el(CardBody, null, children)
		);
	}

	/**
	 * A labelled button that shows and hides a region below it.
	 *
	 * @param {Object}  props
	 * @param {string}  props.label       Button text.
	 * @param {string}  props.id          Region id.
	 * @param {boolean} props.initialOpen Open on first render.
	 * @param {*}       props.children    Region content.
	 */
	function Disclosure({ label, id, initialOpen, children }) {
		const [open, setOpen] = useState(!!initialOpen);
		return el(
			'div',
			{ className: 'post-shield-disclosure' },
			el(
				Button,
				{
					type: 'button',
					variant: 'link',
					'aria-expanded': open,
					'aria-controls': id,
					onClick: () => setOpen(!open),
				},
				(open ? '▾ ' : '▸ ') + label
			),
			open
				? el(
						'div',
						{
							id,
							className:
								'post-shield-disclosure__body tcc-admin__fields',
						},
						children
					)
				: null
		);
	}

	// Shared control props: WordPress 6.7+ wants the modern sizing opt-ins.
	const MODERN = { __nextHasNoMarginBottom: true };
	const MODERN_SIZED = {
		__nextHasNoMarginBottom: true,
		__next40pxDefaultSize: true,
	};

	// ── Status tiles and notices ───────────────────────────────────────────

	function Tile({ label, value, sub }) {
		return el(
			'div',
			{ className: 'tcc-admin__tile' },
			el('p', { className: 'tcc-admin__tile-label' }, label),
			el('div', { className: 'tcc-admin__tile-value' }, value),
			sub ? el('div', { className: 'tcc-admin__tile-sub' }, sub) : null
		);
	}

	function StatusTiles() {
		const s = data.status;
		const bake = data.bake;
		let statusSub = __(
			'No configuration yet. Choose post types and save.',
			'post-404-shield'
		);
		if (s.active && s.generatedAt) {
			statusSub = sprintf(
				/* translators: 1: timestamp, 2: who saved it. */
				__('Last saved %1$s by %2$s', 'post-404-shield'),
				s.generatedAt.replace('T', ' ').slice(0, 16),
				s.generatedBy || '—'
			);
		} else if (s.generatedAt) {
			statusSub = __(
				'Every rule is switched off. Restore a revision or switch a post type on and save.',
				'post-404-shield'
			);
		}
		let rootValue = __('Off', 'post-404-shield');
		let rootSub = __(
			'Pages and posts are not shielded.',
			'post-404-shield'
		);
		if (s.rootTypes.length) {
			rootValue = __('On', 'post-404-shield');
			rootSub = s.rootStale
				? __(
						'Routes changed since the last save — save again.',
						'post-404-shield'
					)
				: s.rootTypes.join(', ');
		}
		return el(
			'div',
			{ className: 'tcc-admin__tiles', 'data-ps': 'status' },
			el(Tile, {
				label: __('Status', 'post-404-shield'),
				value: s.active
					? el(
							Badge,
							{ tone: 'success' },
							__('Active', 'post-404-shield')
						)
					: el(
							Badge,
							{ tone: 'error' },
							__('Inactive', 'post-404-shield')
						),
				sub: statusSub,
			}),
			el(Tile, {
				label: __('Post types shielded', 'post-404-shield'),
				value: String(s.shieldedTypes),
				sub: sprintf(
					/* translators: %d: number of blocked sections. */
					_n(
						'plus %d blocked section',
						'plus %d blocked sections',
						s.blockedBases,
						'post-404-shield'
					),
					s.blockedBases
				),
			}),
			el(Tile, {
				label: __('Themed 404 pages', 'post-404-shield'),
				value: bake.pages,
				sub: sprintf(
					/* translators: %s: how long ago, e.g. "2 hours ago". */
					__('Last built %s', 'post-404-shield'),
					bake.baked
				),
			}),
			el(Tile, {
				label: __('Pages & posts (root mode)', 'post-404-shield'),
				value: rootValue,
				sub: rootSub,
			})
		);
	}

	function Notices() {
		const [notices, setNotices] = useState(data.notices);
		if (!notices.length) {
			return null;
		}
		return el(
			'div',
			{ className: 'tcc-admin__notices', 'data-ps': 'notices' },
			notices.map((notice, index) =>
				el(
					Notice,
					{
						key: index,
						status: notice.status,
						onRemove: () =>
							setNotices(notices.filter((n) => n !== notice)),
					},
					el('p', null, notice.message),
					notice.list && notice.list.length
						? el(
								'ul',
								null,
								notice.list.map((item, i) =>
									el('li', { key: i }, item)
								)
							)
						: null
				)
			)
		);
	}

	// ── Post type rows ─────────────────────────────────────────────────────

	function StatusChecklist({ type, onChange }) {
		return el(
			'fieldset',
			{ className: 'tcc-admin__fieldset' },
			el(
				'legend',
				{ className: 'tcc-admin__field-label' },
				__('Post statuses that count as real', 'post-404-shield')
			),
			el(
				'div',
				{ className: 'tcc-admin__checkbox-grid' },
				data.statuses.map((status) =>
					el(CheckboxControl, {
						...MODERN,
						key: status.name,
						label: status.label,
						checked: type.statuses.includes(status.name),
						onChange: (checked) =>
							onChange({
								statuses: checked
									? type.statuses.concat(status.name)
									: type.statuses.filter(
											(s) => s !== status.name
										),
							}),
					})
				)
			),
			el(
				'p',
				{ className: 'components-base-control__help' },
				__(
					'Posts in these statuses are let through. Usually just Published.',
					'post-404-shield'
				)
			)
		);
	}

	function CacheFields({ type, onChange, idPrefix }) {
		return el(
			Disclosure,
			{
				label: __('Advanced: cache times', 'post-404-shield'),
				id: idPrefix + '-cache',
			},
			el(TextControl, {
				...MODERN_SIZED,
				type: 'number',
				min: 0,
				label: __(
					'How long browsers and the CDN may keep this 404 (seconds)',
					'post-404-shield'
				),
				help: __(
					'Empty uses the default of 60 seconds. Keep it short: a post published later must not stay hidden behind a saved 404.',
					'post-404-shield'
				),
				value: type.cacheTtl,
				onChange: (value) => onChange({ cacheTtl: value }),
			}),
			el(TextControl, {
				...MODERN_SIZED,
				type: 'number',
				min: 0,
				label: __(
					'How long the CDN alone may keep it (seconds)',
					'post-404-shield'
				),
				help: __('Empty follows the setting above.', 'post-404-shield'),
				value: type.edgeTtl,
				onChange: (value) => onChange({ edgeTtl: value }),
			})
		);
	}

	function TypeFields({ type, onChange }) {
		const bases = splitLines(type.urlBase);
		const exampleBase =
			bases[0] || type.derivedBase || type.rewriteSlug || 'base';
		const seenDiffers =
			type.seenBases.length > 0 &&
			type.seenBases.join('\n') !== bases.slice().sort().join('\n');
		const mismatch =
			type.enabled &&
			type.seenBases.length > 0 &&
			bases.length > 0 &&
			!bases.some((base) => type.seenBases.includes(base));
		const idPrefix = 'post-shield-' + type.cpt;

		const fields = [];

		if (type.derivedBase) {
			fields.push(
				el(
					'p',
					{ key: 'derived', className: 'tcc-admin__meta' },
					sprintf(
						/* translators: %s: URL base. */
						__(
							'URL base: %s — taken from Settings → Permalinks.',
							'post-404-shield'
						),
						type.derivedBase
					)
				)
			);
		} else if (!type.unsupportedPost && !type.root) {
			fields.push(
				el(
					'div',
					{ key: 'bases' },
					el(TextareaControl, {
						...MODERN,
						label: __('URL bases', 'post-404-shield'),
						rows: Math.max(2, Math.min(8, bases.length + 1)),
						help: __(
							'The part of the address before the post’s name, without the language — for example products/shoes. One per line if the type uses several; they all share one list of real names.',
							'post-404-shield'
						),
						value: type.urlBase,
						onChange: (value) => onChange({ urlBase: value }),
					}),
					seenDiffers
						? el(
								'p',
								{
									className:
										'tcc-admin__meta post-shield-seen',
								},
								sprintf(
									/* translators: %s: comma-separated URL bases. */
									__(
										'Recent real addresses for this type use: %s',
										'post-404-shield'
									),
									type.seenBases.join(', ')
								),
								' ',
								el(
									Button,
									{
										type: 'button',
										variant: 'link',
										onClick: () =>
											onChange({
												urlBase:
													type.seenBases.join('\n'),
											}),
									},
									__('Use these', 'post-404-shield')
								)
							)
						: null,
					mismatch
						? el(
								Notice,
								{ status: 'warning', isDismissible: false },
								__(
									'None of these bases match this type’s real addresses, so the shield is not protecting it. Check the bases above.',
									'post-404-shield'
								)
							)
						: null
				)
			);
		}

		// Root types have no base and are always full-path: nothing to choose.
		if (type.offerMatch && !type.unsupportedPost && !type.root) {
			fields.push(
				el(SelectControl, {
					...MODERN_SIZED,
					key: 'match',
					label: __(
						'How real addresses are recognised',
						'post-404-shield'
					),
					value: type.match,
					options: [
						{
							value: 'slug',
							label: __(
								'By the post’s own name (recommended)',
								'post-404-shield'
							),
						},
						{
							value: 'full-path',
							label: __(
								'By the full address, including parent posts',
								'post-404-shield'
							),
						},
					],
					help: __(
						'Full addresses suit sites with only a few nested posts: the list stores every post’s complete address, so it grows with every post, and the extra-levels rule below no longer applies.',
						'post-404-shield'
					),
					onChange: (value) => onChange({ match: value }),
				})
			);
		}

		fields.push(
			el(CheckboxControl, {
				...MODERN,
				key: 'pagination',
				label: __('Allow page 2, 3 … of a post', 'post-404-shield'),
				help: __(
					'Lets /page/2/, /2/ and comment pages through for real posts. Untick only if this type never splits a post across pages — made-up page numbers are then blocked too.',
					'post-404-shield'
				),
				checked: type.allowPagination,
				onChange: (checked) => onChange({ allowPagination: checked }),
			})
		);

		// Depth only means something when a post can have posts below it.
		if (
			type.hierarchical &&
			!type.root &&
			!type.unsupportedPost &&
			'slug' === type.match
		) {
			fields.push(
				el(
					'div',
					{
						key: 'depth',
						className: 'tcc-admin__grid',
						'data-ps': 'depth',
					},
					el(TextControl, {
						...MODERN_SIZED,
						type: 'number',
						min: 0,
						label: __(
							'Extra levels allowed after a post’s address',
							'post-404-shield'
						),
						help: sprintf(
							/* translators: %s: the type's URL base, e.g. products/shoes. */
							__(
								'How many more folders a real address can have after the post’s name. 0 allows only the post itself, like /%1$s/post-name/. 1 also allows one level below it, like /%1$s/post-name/gallery/. Leave it empty for no limit. Made-up post names are always blocked, however many levels they have.',
								'post-404-shield'
							),
							exampleBase
						),
						value: type.depthAllowed,
						onChange: (value) => onChange({ depthAllowed: value }),
					}),
					el(SelectControl, {
						...MODERN_SIZED,
						label: __(
							'When an address has more levels than that',
							'post-404-shield'
						),
						value: type.depthAction,
						options: [
							{
								value: 'redirect',
								label: __(
									'Redirect to the allowed part of the address',
									'post-404-shield'
								),
							},
							{
								value: '404',
								label: __(
									'Show the 404 page',
									'post-404-shield'
								),
							},
							{
								value: 'passthrough',
								label: __(
									'Let WordPress decide',
									'post-404-shield'
								),
							},
						],
						help: __(
							'Page 2, 3 … of a post and its feed never count as extra levels while “Allow page 2, 3 … of a post” is ticked.',
							'post-404-shield'
						),
						onChange: (value) => onChange({ depthAction: value }),
					})
				)
			);
		}

		fields.push(
			el(StatusChecklist, { key: 'statuses', type, onChange }),
			el(TextareaControl, {
				...MODERN,
				key: 'reserved',
				label: type.root
					? __(
							'Other real addresses to let through',
							'post-404-shield'
						)
					: __(
							'Other real addresses under this base',
							'post-404-shield'
						),
				rows: 2,
				help: type.root
					? __(
							'One name per line, for addresses at the top level that are not pages or posts.',
							'post-404-shield'
						)
					: sprintf(
							/* translators: %s: the type's URL base, e.g. team. */
							__(
								'One name per line, for addresses under /%s/ that are not posts of this type — usually a page. The shield only lets them through; WordPress must still be able to show that page.',
								'post-404-shield'
							),
							exampleBase
						),
				value: type.reserved,
				onChange: (value) => onChange({ reserved: value }),
			})
		);

		if (type.reservedDerived.length) {
			fields.push(
				el(
					'div',
					{ key: 'reserved-derived', 'data-ps': 'reserved-derived' },
					el(
						'span',
						{ className: 'tcc-admin__field-label' },
						__(
							'Let through because a redirect exists',
							'post-404-shield'
						)
					),
					el(Chips, { items: type.reservedDerived }),
					el(
						'p',
						{ className: 'components-base-control__help' },
						__(
							'Read from Rank Math redirects every time you save, and every night. You don’t need to add these yourself. A name ending in * covers every name that starts with it.',
							'post-404-shield'
						)
					)
				)
			);
		}

		fields.push(
			el(CacheFields, { key: 'cache', type, onChange, idPrefix })
		);

		return el('div', { className: 'tcc-admin__fields' }, fields);
	}

	function TypeRow({ type, onChange }) {
		const [open, setOpen] = useState(false);
		const regionId = 'post-shield-type-' + type.cpt;
		const bases = splitLines(type.urlBase);

		let summary;
		if (type.root) {
			summary = __(
				'Lives at the top level (no URL base)',
				'post-404-shield'
			);
		} else if (type.unsupportedPost) {
			summary = __(
				'The permalink structure has no fixed base, so posts cannot be shielded.',
				'post-404-shield'
			);
		} else if (type.derivedBase) {
			summary = '/' + type.derivedBase + '/';
		} else {
			summary = bases.length
				? bases.map((base) => '/' + base + '/').join('  ·  ')
				: __('No URL base yet', 'post-404-shield');
		}

		return el(
			'div',
			{
				className: 'post-shield-type' + (open ? ' is-open' : ''),
				'data-post-type': type.cpt,
			},
			el(
				'div',
				{ className: 'post-shield-type__header' },
				el(
					'div',
					{ className: 'post-shield-type__toggle' },
					el(ToggleControl, {
						...MODERN,
						label: type.label,
						checked: type.enabled,
						disabled: type.unsupportedPost,
						onChange: (checked) => onChange({ enabled: checked }),
					})
				),
				el(
					'div',
					{ className: 'post-shield-type__meta' },
					el('code', { className: 'tcc-admin__chip' }, type.cpt),
					!type.registered
						? el(
								Badge,
								{ tone: 'error' },
								__(
									'Post type not registered',
									'post-404-shield'
								)
							)
						: null,
					type.enabled
						? el(
								Badge,
								{ tone: 'success' },
								__('Shielded', 'post-404-shield')
							)
						: null,
					!type.enabled && type.hasEntry
						? el(
								Badge,
								null,
								__('Off — settings kept', 'post-404-shield')
							)
						: null,
					el(
						'span',
						{
							className:
								'tcc-admin__meta post-shield-type__summary',
						},
						summary
					)
				),
				el(
					Button,
					{
						type: 'button',
						variant: 'secondary',
						size: 'compact',
						'aria-expanded': open,
						'aria-controls': regionId,
						onClick: () => setOpen(!open),
					},
					open
						? __('Hide settings', 'post-404-shield')
						: __('Settings', 'post-404-shield')
				)
			),
			open
				? el(
						'div',
						{ id: regionId, className: 'post-shield-type__body' },
						el(TypeFields, { type, onChange })
					)
				: null
		);
	}

	// ── Tabs ───────────────────────────────────────────────────────────────

	function TypesTab({ types, updateType }) {
		const [filter, setFilter] = useState('');
		const based = types.filter((t) => !t.root);
		const needle = filter.trim().toLowerCase();
		const visible = needle
			? based.filter(
					(t) =>
						t.label.toLowerCase().includes(needle) ||
						t.cpt.includes(needle) ||
						t.urlBase.includes(needle)
				)
			: based;
		const on = visible.filter((t) => t.enabled).sort(byLabel);
		const off = visible.filter((t) => !t.enabled).sort(byLabel);

		const group = (title, rows) =>
			rows.length
				? el(
						'div',
						{ className: 'post-shield-group' },
						el(
							'h3',
							{ className: 'post-shield-group__title' },
							title
						),
						rows.map((type) =>
							el(TypeRow, {
								key: type.cpt,
								type,
								onChange: (patch) =>
									updateType(type.cpt, patch),
							})
						)
					)
				: null;

		return el(
			SectionCard,
			{
				title: __('Post types', 'post-404-shield'),
				description: __(
					'Switch a post type on to shield it. Its URL base is filled in from the addresses its posts really use; open Settings to check it or change the details.',
					'post-404-shield'
				),
				actions: el(TextControl, {
					...MODERN_SIZED,
					className: 'post-shield-filter',
					label: __('Filter post types', 'post-404-shield'),
					hideLabelFromVision: true,
					placeholder: __('Filter post types…', 'post-404-shield'),
					type: 'search',
					value: filter,
					onChange: setFilter,
				}),
			},
			group(
				sprintf(
					/* translators: %d: number of post types. */
					__('Shielded (%d)', 'post-404-shield'),
					on.length
				),
				on
			),
			group(
				sprintf(
					/* translators: %d: number of post types. */
					__('Not shielded (%d)', 'post-404-shield'),
					off.length
				),
				off
			),
			!on.length && !off.length
				? el(
						'p',
						{ className: 'tcc-admin__meta' },
						__(
							'No post types match that filter.',
							'post-404-shield'
						)
					)
				: null
		);
	}

	function RootTab({
		types,
		updateType,
		operator,
		setOperator,
		rootConfirm,
		setRootConfirm,
	}) {
		const rootTypes = types.filter((t) => t.root);
		const excluded = data.excluded;
		return el(
			'div',
			{ className: 'tcc-admin__stack' },
			el(
				SectionCard,
				{
					title: __('Pages & posts (root mode)', 'post-404-shield'),
					description: __(
						'Root mode shields pages and posts that sit directly after the language, like /global/about-us/.',
						'post-404-shield'
					),
				},
				el(
					'div',
					{ className: 'tcc-admin__fields' },
					el(
						'div',
						{
							className:
								'tcc-admin__callout tcc-admin__callout--warning',
						},
						__(
							'With root mode on, the shield decides every address that no other rule covers, so a real address it does not know about would show the 404 page. Switch it on only as a planned change, with pages and posts together. Saving checks every real address first and stops if any would be blocked.',
							'post-404-shield'
						)
					),
					rootTypes.length
						? rootTypes.map((type) =>
								el(TypeRow, {
									key: type.cpt,
									type,
									onChange: (patch) =>
										updateType(type.cpt, patch),
								})
							)
						: el(
								'p',
								{ className: 'tcc-admin__meta' },
								__(
									'This site’s permalinks give posts a fixed base, so they are listed with the other post types.',
									'post-404-shield'
								)
							),
					!excluded.rootActive
						? el(CheckboxControl, {
								...MODERN,
								label: __(
									'I understand what root mode does (required to switch on pages or posts)',
									'post-404-shield'
								),
								checked: rootConfirm,
								onChange: setRootConfirm,
							})
						: null
				)
			),
			el(
				SectionCard,
				{
					title: __(
						'Addresses root mode always leaves to WordPress',
						'post-404-shield'
					),
					description: __(
						'Any address starting with one of these goes straight to WordPress. The list is read from WordPress itself — its routes, post types, taxonomies, feeds, the REST API and redirect sources — and stored on save, so add a new route the normal WordPress way and save here.',
						'post-404-shield'
					),
				},
				el(
					'div',
					{ className: 'tcc-admin__fields' },
					el(
						Disclosure,
						{
							label: sprintf(
								/* translators: %d: number of addresses. */
								__(
									'Show the %d detected from WordPress',
									'post-404-shield'
								),
								excluded.derived.length
							),
							id: 'post-shield-excluded-derived',
						},
						el(Chips, { items: excluded.derived })
					),
					el(
						'div',
						null,
						el(
							'span',
							{ className: 'tcc-admin__field-label' },
							__('Always left alone', 'post-404-shield')
						),
						el(Chips, { items: excluded.floor, muted: true })
					),
					el(TextareaControl, {
						...MODERN,
						label: __(
							'Extra addresses to leave alone',
							'post-404-shield'
						),
						rows: 3,
						help: __(
							'Only for a route WordPress does not know about, such as a path another server handles. Most sites leave this empty. One per line: letters, digits, dots, hyphens and underscores; end with * to match everything that starts with it.',
							'post-404-shield'
						),
						value: operator,
						onChange: setOperator,
					})
				)
			)
		);
	}

	function BlockedTab({ blocks, setBlocks }) {
		const update = (index, patch) =>
			setBlocks(
				blocks.map((row, i) =>
					i === index ? { ...row, ...patch } : row
				)
			);
		return el(
			SectionCard,
			{
				title: __('Blocked sections', 'post-404-shield'),
				description: __(
					'Sections of the site with no real content at all. Every address inside them gets the 404 page straight away, and it is cached for longer.',
					'post-404-shield'
				),
				actions: el(
					Button,
					{
						type: 'button',
						variant: 'secondary',
						onClick: () =>
							setBlocks(
								blocks.concat({
									label: '',
									urlBase: '',
									enabled: true,
									cacheTtl: '',
								})
							),
					},
					__('Add blocked section', 'post-404-shield')
				),
			},
			blocks.length
				? blocks.map((row, index) =>
						el(
							'div',
							{
								key: index,
								className: 'post-shield-block',
								'data-ps': 'block-row',
							},
							el(
								'div',
								{ className: 'post-shield-block__header' },
								el(ToggleControl, {
									...MODERN,
									label:
										row.label ||
										__('New section', 'post-404-shield'),
									checked: row.enabled,
									onChange: (checked) =>
										update(index, { enabled: checked }),
								}),
								el(
									Button,
									{
										type: 'button',
										variant: 'tertiary',
										isDestructive: true,
										onClick: () =>
											setBlocks(
												blocks.filter(
													(r, i) => i !== index
												)
											),
									},
									__('Remove', 'post-404-shield')
								)
							),
							el(
								'div',
								{ className: 'tcc-admin__grid' },
								el(TextControl, {
									...MODERN_SIZED,
									label: __('Name', 'post-404-shield'),
									help: __(
										'A short name for this rule, like old-section.',
										'post-404-shield'
									),
									value: row.label,
									onChange: (value) =>
										update(index, { label: value }),
								}),
								el(TextareaControl, {
									...MODERN,
									label: __(
										'Sections (one per line)',
										'post-404-shield'
									),
									rows: 2,
									help: __(
										'The part of the address after the language, like old-section.',
										'post-404-shield'
									),
									value: row.urlBase,
									onChange: (value) =>
										update(index, { urlBase: value }),
								}),
								el(TextControl, {
									...MODERN_SIZED,
									type: 'number',
									min: 0,
									label: __(
										'Cache time (seconds)',
										'post-404-shield'
									),
									help: __(
										'Empty uses the default of one hour.',
										'post-404-shield'
									),
									value: row.cacheTtl,
									onChange: (value) =>
										update(index, { cacheTtl: value }),
								})
							)
						)
					)
				: el(
						'p',
						{ className: 'tcc-admin__meta' },
						__('No blocked sections.', 'post-404-shield')
					)
		);
	}

	function SiteTab({ site, updateSite, onDisable }) {
		const modeHelp =
			'wpml-directory' === site.localeMode
				? sprintf(
						/* translators: %s: the detected language pattern. */
						__(
							'Detected pattern: %s. Shielded addresses must start with a language; addresses without one are left to WordPress.',
							'post-404-shield'
						),
						site.detectedPattern
					)
				: null;
		const keepOptions = [];
		for (let i = 10; i <= 100; i += 10) {
			keepOptions.push({ value: String(i), label: String(i) });
		}
		return el(
			'div',
			{ className: 'tcc-admin__stack' },
			el(
				SectionCard,
				{
					title: __('Language in the address', 'post-404-shield'),
					description: __(
						'How this site puts the language into its addresses.',
						'post-404-shield'
					),
				},
				el(
					'div',
					{ className: 'tcc-admin__fields' },
					el(SelectControl, {
						...MODERN_SIZED,
						label: __('Language URL prefix', 'post-404-shield'),
						value: site.localeMode,
						options: [
							{
								value: 'none',
								label: __(
									'None — addresses have no language folder',
									'post-404-shield'
								),
							},
							{
								value: 'wpml-directory',
								label: __(
									'WPML language folders (detected automatically)',
									'post-404-shield'
								),
							},
							{
								value: 'custom',
								label: __('Custom pattern', 'post-404-shield'),
							},
						],
						help: modeHelp,
						onChange: (value) => updateSite({ localeMode: value }),
					}),
					'wpml-directory' === site.localeMode &&
						site.languages.length
						? el(
								Disclosure,
								{
									label: sprintf(
										/* translators: %d: number of languages. */
										_n(
											'Show %d active language',
											'Show %d active languages',
											site.languages.length,
											'post-404-shield'
										),
										site.languages.length
									),
									id: 'post-shield-languages',
								},
								el(Chips, { items: site.languages })
							)
						: null,
					'custom' === site.localeMode
						? el(TextControl, {
								...MODERN_SIZED,
								label: __(
									'Language pattern',
									'post-404-shield'
								),
								placeholder: '[a-z]{2}-[a-z]{2}|global',
								help: __(
									'Just the pattern itself — no slashes, anchors or brackets around it. For example [a-z]{2}-[a-z]{2}|global.',
									'post-404-shield'
								),
								value: site.localePattern,
								onChange: (value) =>
									updateSite({ localePattern: value }),
							})
						: null
				)
			),
			el(
				SectionCard,
				{
					title: __('Revisions', 'post-404-shield'),
					description: __(
						'Every save keeps the configuration it replaced, so it can be restored from the Revisions tab.',
						'post-404-shield'
					),
				},
				el(SelectControl, {
					...MODERN_SIZED,
					label: __('Config revisions to keep', 'post-404-shield'),
					value: site.keep,
					options: keepOptions,
					onChange: (value) => updateSite({ keep: value }),
				})
			),
			el(
				SectionCard,
				{
					title: __('Switch the shield off', 'post-404-shield'),
					description: __(
						'Turns every rule off at once. The current configuration is kept as a revision, so you can restore it.',
						'post-404-shield'
					),
				},
				el(
					Button,
					{
						type: 'button',
						variant: 'secondary',
						isDestructive: true,
						onClick: onDisable,
					},
					__('Disable shield…', 'post-404-shield')
				)
			)
		);
	}

	// ── Maintenance (background jobs) ──────────────────────────────────────

	const statusUrl = (subject) =>
		data.urls.ajax +
		'?action=post_shield_status&subject=' +
		encodeURIComponent(subject) +
		'&_wpnonce=' +
		encodeURIComponent(data.nonces.status);

	/**
	 * Queue a background job, then poll until it lands.
	 *
	 * @param {string}   action   admin-ajax action.
	 * @param {string}   nonce    Its nonce.
	 * @param {Object}   fields   Extra POST fields.
	 * @param {string}   subject  Status subject (post type, or 404).
	 * @param {Object}   handlers onQueued, onProgress, onDone, onGiveUp, onError.
	 * @param {Function} isLive   Returns false once the caller has unmounted.
	 */
	function runJob(action, nonce, fields, subject, handlers, isLive) {
		const body = new window.FormData();
		body.append('action', action);
		body.append('_wpnonce', nonce);
		Object.keys(fields).forEach((key) => body.append(key, fields[key]));

		const isBake = '404' === subject;
		const maxTries = isBake ? 120 : 30; // Bake batches chain per cron tick.
		const delay = isBake ? 5000 : 3000;

		const poll = (attempt) => {
			if (!isLive()) {
				return;
			}
			if (attempt >= maxTries) {
				handlers.onGiveUp();
				return;
			}
			window.setTimeout(() => {
				window
					.fetch(statusUrl(subject), { credentials: 'same-origin' })
					.then((response) => response.json())
					.then((result) => {
						if (!isLive()) {
							return;
						}
						if (result.success && !result.data.running) {
							handlers.onDone(result.data);
							return;
						}
						if (
							result.success &&
							result.data.info &&
							result.data.info.progress
						) {
							handlers.onProgress(result.data.info.progress);
						}
						poll(attempt + 1);
					})
					.catch(() => poll(attempt + 1));
			}, delay);
		};

		window
			.fetch(data.urls.ajax, {
				method: 'POST',
				credentials: 'same-origin',
				body,
			})
			.then((response) => response.json())
			.then((result) => {
				if (!result.success) {
					throw new Error(
						(result.data && result.data.message) ||
							__('Request failed — try again.', 'post-404-shield')
					);
				}
				handlers.onQueued(result.data.message);
				poll(0);
			})
			.catch((error) =>
				handlers.onError(
					error.message ||
						__('Request failed — try again.', 'post-404-shield')
				)
			);
	}

	function useLive() {
		const live = useRef(true);
		useEffect(
			() => () => {
				live.current = false;
			},
			[]
		);
		return () => live.current;
	}

	function JobStatus({ message, tone }) {
		return el(
			'span',
			{
				className:
					'tcc-admin__meta post-shield-job' +
					(tone ? ' post-shield-job--' + tone : ''),
				role: 'status',
				'aria-live': 'polite',
			},
			message
		);
	}

	function AllowlistRow({ row }) {
		const [info, setInfo] = useState(row);
		const [busy, setBusy] = useState(false);
		const [message, setMessage] = useState('');
		const [tone, setTone] = useState('');
		const isLive = useLive();

		const rebuild = () => {
			setBusy(true);
			setTone('');
			setMessage(__('Queuing…', 'post-404-shield'));
			runJob(
				'post_shield_rebuild',
				data.nonces.rebuild,
				{ post_type: row.postType },
				row.postType,
				{
					onQueued: setMessage,
					onProgress: () => {},
					onDone: (result) => {
						setInfo({ ...info, ...result.info });
						setBusy(false);
						setTone('ok');
						setMessage(__('Done.', 'post-404-shield'));
						speak(
							sprintf(
								/* translators: %s: post type name. */
								__(
									'Allowlist for %s rebuilt.',
									'post-404-shield'
								),
								row.label
							)
						);
					},
					onGiveUp: () => {
						setBusy(false);
						setMessage(
							__(
								'Still running in the background — refresh in a minute.',
								'post-404-shield'
							)
						);
					},
					onError: (error) => {
						setBusy(false);
						setTone('error');
						setMessage(error);
					},
				},
				isLive
			);
		};

		return el(
			'div',
			{ className: 'tcc-admin__row', 'data-post-type': row.postType },
			el(
				'div',
				{ className: 'tcc-admin__row-main' },
				el('strong', null, row.label),
				el('code', { className: 'tcc-admin__chip' }, row.postType),
				!info.registered
					? el(
							Badge,
							{ tone: 'error' },
							__(
								'Post type not registered — shielding nothing',
								'post-404-shield'
							)
						)
					: null,
				el(
					'span',
					{ className: 'tcc-admin__meta' },
					sprintf(
						/* translators: 1: number of names, 2: how long ago. */
						__('%1$s names · updated %2$s', 'post-404-shield'),
						info.entries,
						info.updated
					)
				),
				el(JobStatus, { message, tone })
			),
			el(
				'div',
				{ className: 'tcc-admin__row-actions' },
				busy ? el(Spinner) : null,
				el(
					Button,
					{
						type: 'button',
						variant: 'secondary',
						disabled: busy,
						onClick: rebuild,
					},
					__('Rebuild allowlist', 'post-404-shield')
				)
			)
		);
	}

	function BakeRow() {
		const [info, setInfo] = useState(data.bake);
		const [busy, setBusy] = useState(false);
		const [progress, setProgress] = useState(data.bake.progress);
		const [message, setMessage] = useState('');
		const [tone, setTone] = useState('');
		const isLive = useLive();

		const bake = () => {
			setBusy(true);
			setTone('');
			setMessage(__('Queuing…', 'post-404-shield'));
			runJob(
				'post_shield_bake_404',
				data.nonces.bake,
				{},
				'404',
				{
					onQueued: setMessage,
					onProgress: (next) => {
						setProgress(next);
						setMessage(
							sprintf(
								/* translators: 1: pages built so far, 2: total. */
								__('Building %1$d of %2$d…', 'post-404-shield'),
								next.done,
								next.total
							)
						);
					},
					onDone: (result) => {
						setInfo(result.info);
						setProgress(null);
						setBusy(false);
						const failed = result.failed || [];
						if (failed.length) {
							setTone('error');
							setMessage(
								sprintf(
									/* translators: %s: comma-separated language codes. */
									__(
										'Done — these failed: %s',
										'post-404-shield'
									),
									failed.join(', ')
								)
							);
						} else {
							setTone('ok');
							setMessage(__('Done.', 'post-404-shield'));
						}
						speak(__('404 pages regenerated.', 'post-404-shield'));
					},
					onGiveUp: () => {
						setBusy(false);
						setMessage(
							__(
								'Still running in the background — refresh in a minute.',
								'post-404-shield'
							)
						);
					},
					onError: (error) => {
						setBusy(false);
						setTone('error');
						setMessage(error);
					},
				},
				isLive
			);
		};

		const percent =
			progress && progress.total
				? Math.round((100 * progress.done) / progress.total)
				: 0;

		return el(
			'div',
			{ className: 'tcc-admin__row', 'data-ps': 'bake' },
			el(
				'div',
				{ className: 'tcc-admin__row-main' },
				el(
					'span',
					{ className: 'tcc-admin__meta' },
					sprintf(
						/* translators: 1: number of pages, 2: how long ago. */
						__('%1$s pages · last built %2$s', 'post-404-shield'),
						info.pages,
						info.baked
					)
				),
				progress && progress.total
					? el(
							'span',
							{
								className: 'tcc-admin__progress',
								role: 'progressbar',
								'aria-valuemin': 0,
								'aria-valuemax': 100,
								'aria-valuenow': percent,
								'aria-label': __(
									'404 page build progress',
									'post-404-shield'
								),
							},
							el('span', {
								className: 'tcc-admin__progress-fill',
								style: { width: percent + '%' },
							})
						)
					: null,
				el(JobStatus, { message, tone })
			),
			el(
				'div',
				{ className: 'tcc-admin__row-actions' },
				busy ? el(Spinner) : null,
				el(
					Button,
					{
						type: 'button',
						variant: 'secondary',
						disabled: busy,
						onClick: bake,
					},
					__('Regenerate 404 pages', 'post-404-shield')
				)
			)
		);
	}

	function MaintenanceTab() {
		return el(
			'div',
			{ className: 'tcc-admin__stack' },
			el(
				SectionCard,
				{
					title: __('Allowlists', 'post-404-shield'),
					description: __(
						'One list of real post names per shielded post type. They update on their own; rebuild one by hand after a bulk import or anything else that skips normal saving.',
						'post-404-shield'
					),
				},
				data.allowlists.length
					? data.allowlists.map((row) =>
							el(AllowlistRow, { key: row.postType, row })
						)
					: el(
							'p',
							{ className: 'tcc-admin__meta' },
							__(
								'No post types are shielded yet — switch one on and save first.',
								'post-404-shield'
							)
						)
			),
			el(
				SectionCard,
				{
					title: __('Themed 404 pages', 'post-404-shield'),
					description: __(
						'The shield shows a saved copy of the site’s 404 page, one per language. It is refreshed weekly; regenerate it after changing menus, the theme or translations.',
						'post-404-shield'
					),
				},
				el(BakeRow)
			)
		);
	}

	function RevisionsTab() {
		const revisions = data.revisions;
		return el(
			SectionCard,
			{
				title: __('Config revisions', 'post-404-shield'),
				description: sprintf(
					/* translators: %d: number of revisions kept. */
					__(
						'Every save keeps the configuration it replaced, newest first (times are UTC). Keeping %d.',
						'post-404-shield'
					),
					revisions.keep
				),
			},
			revisions.items.length
				? revisions.items.map((revision) =>
						el(
							'div',
							{
								key: revision.stamp,
								className: 'tcc-admin__row',
								'data-ps-revision': revision.stamp,
							},
							el(
								'div',
								{ className: 'tcc-admin__row-main' },
								el(
									'code',
									{ className: 'tcc-admin__chip' },
									revision.stamp
								),
								revision.valid
									? el(
											'span',
											{ className: 'tcc-admin__meta' },
											sprintf(
												/* translators: 1: timestamp, 2: who saved it. */
												__(
													'saved %1$s by %2$s',
													'post-404-shield'
												),
												revision.generatedAt || '—',
												revision.generatedBy || '—'
											)
										)
									: el(
											Badge,
											{ tone: 'error' },
											__(
												'Not a valid config (corrupt?)',
												'post-404-shield'
											)
										)
							),
							revision.valid
								? el(
										Button,
										{
											variant: 'secondary',
											href: revision.restoreUrl,
										},
										__('Restore…', 'post-404-shield')
									)
								: null
						)
					)
				: el(
						'p',
						{ className: 'tcc-admin__meta' },
						__('No revisions yet.', 'post-404-shield')
					)
		);
	}

	// ── The app ────────────────────────────────────────────────────────────

	function HiddenFields({ site, types, blocks, operator, rootConfirm }) {
		const inputs = [
			el(Hidden, {
				key: 'action',
				name: 'action',
				value: 'post_shield_save_config',
			}),
			el(Hidden, {
				key: 'nonce',
				name: '_wpnonce',
				value: data.nonces.save,
			}),
			el(Hidden, {
				key: 'referer',
				name: '_wp_http_referer',
				value: data.urls.page,
			}),
			el(Hidden, {
				key: 'mode',
				name: 'ps_locale_mode',
				value: site.localeMode,
			}),
			el(Hidden, {
				key: 'pattern',
				name: 'ps_locale_pattern',
				value: site.localePattern,
			}),
			el(Hidden, { key: 'keep', name: 'ps_keep', value: site.keep }),
			el(Hidden, {
				key: 'operator',
				name: 'ps_excluded_operator',
				value: operator,
			}),
		];
		if (rootConfirm) {
			inputs.push(
				el(Hidden, {
					key: 'confirm',
					name: 'ps_root_confirm',
					value: '1',
				})
			);
		}

		types.forEach((type) => {
			const field = 'ps_types[' + type.cpt + ']';
			const add = (suffix, value) =>
				inputs.push(
					el(Hidden, {
						key: field + suffix + inputs.length,
						name: field + suffix,
						value,
					})
				);
			if (type.enabled) {
				add('[enabled]', '1');
			}
			if (!type.root && !type.unsupportedPost && !type.derivedBase) {
				add('[url_base]', type.urlBase);
			}
			// Posted only where the screen shows them — matching when offered
			// (hierarchical, or stored as full-path), depth for hierarchical slug
			// types. The server keeps the stored value of anything not posted.
			if (type.offerMatch && !type.root && !type.unsupportedPost) {
				add('[match]', type.match);
			}
			if (
				type.hierarchical &&
				!type.root &&
				!type.unsupportedPost &&
				'slug' === type.match
			) {
				add('[depth_allowed]', type.depthAllowed);
				add('[depth_action]', type.depthAction);
			}
			if (type.allowPagination) {
				add('[allow_pagination]', '1');
			}
			type.statuses.forEach((status) => add('[post_status][]', status));
			add('[reserved]', type.reserved);
			add('[cache_ttl]', type.cacheTtl);
			add('[edge_ttl]', type.edgeTtl);
		});

		blocks.forEach((row, index) => {
			const field = 'ps_blocks[' + index + ']';
			if (row.enabled) {
				inputs.push(
					el(Hidden, {
						key: field + 'e',
						name: field + '[enabled]',
						value: '1',
					})
				);
			}
			inputs.push(
				el(Hidden, {
					key: field + 'l',
					name: field + '[label]',
					value: row.label,
				}),
				el(Hidden, {
					key: field + 'b',
					name: field + '[url_base]',
					value: row.urlBase,
				}),
				el(Hidden, {
					key: field + 't',
					name: field + '[cache_ttl]',
					value: row.cacheTtl,
				})
			);
		});

		return el(Fragment, null, inputs);
	}

	function DisableModal({ onClose }) {
		return el(
			Modal,
			{
				title: __('Disable the shield?', 'post-404-shield'),
				onRequestClose: onClose,
				size: 'medium',
			},
			el(
				'p',
				null,
				__(
					'Every rule is switched off, so made-up addresses reach WordPress again. The current configuration is kept as a revision and can be restored.',
					'post-404-shield'
				)
			),
			el(
				'form',
				{ method: 'post', action: data.urls.adminPost },
				el(Hidden, { name: 'action', value: 'post_shield_disable' }),
				el(Hidden, { name: '_wpnonce', value: data.nonces.disable }),
				el(
					Flex,
					{
						justify: 'flex-end',
						gap: 3,
						className: 'post-shield-modal-actions',
					},
					el(
						Button,
						{
							type: 'button',
							variant: 'tertiary',
							onClick: onClose,
						},
						__('Cancel', 'post-404-shield')
					),
					el(
						Button,
						{
							type: 'submit',
							variant: 'primary',
							isDestructive: true,
						},
						__('Disable shield', 'post-404-shield')
					)
				)
			)
		);
	}

	function initialTab(tabs) {
		const names = tabs.map((tab) => tab.name);
		const fromHash = window.location.hash.replace(/^#/, '');
		if (names.includes(fromHash)) {
			return fromHash;
		}
		const stored = storage.get();
		return names.includes(stored) ? stored : names[0];
	}

	function App() {
		const [site, setSite] = useState(data.form.site);
		const [types, setTypes] = useState(data.form.types);
		const [blocks, setBlocksState] = useState(data.form.blocks);
		const [operator, setOperatorState] = useState(
			data.form.excludedOperator
		);
		const [rootConfirm, setRootConfirm] = useState(false);
		const [dirty, setDirty] = useState(false);
		const [submitting, setSubmitting] = useState(false);
		const [disabling, setDisabling] = useState(false);

		useEffect(() => {
			if (!dirty || submitting) {
				return undefined;
			}
			const warn = (event) => {
				event.preventDefault();
				event.returnValue = '';
			};
			window.addEventListener('beforeunload', warn);
			return () => window.removeEventListener('beforeunload', warn);
		}, [dirty, submitting]);

		const touch = () => setDirty(true);
		const updateType = (cpt, patch) => {
			touch();
			setTypes((current) =>
				current.map((type) =>
					type.cpt === cpt ? { ...type, ...patch } : type
				)
			);
		};
		const updateSite = (patch) => {
			touch();
			setSite((current) => ({ ...current, ...patch }));
		};
		const setBlocks = (next) => {
			touch();
			setBlocksState(next);
		};
		const setOperator = (next) => {
			touch();
			setOperatorState(next);
		};

		const tabs = [
			{ name: 'types', title: __('Post types', 'post-404-shield') },
			{ name: 'root', title: __('Pages & posts', 'post-404-shield') },
			{
				name: 'blocked',
				title: __('Blocked sections', 'post-404-shield'),
			},
			{ name: 'site', title: __('Site settings', 'post-404-shield') },
			{
				name: 'maintenance',
				title: __('Maintenance', 'post-404-shield'),
			},
			{ name: 'revisions', title: __('Revisions', 'post-404-shield') },
		];

		const rememberTab = (name) => {
			storage.set(name);
			if (window.history && window.history.replaceState) {
				window.history.replaceState(null, '', '#' + name);
			}
		};

		const renderTab = (tab) => {
			let content;
			switch (tab.name) {
				case 'root':
					content = el(RootTab, {
						types,
						updateType,
						operator,
						setOperator,
						rootConfirm,
						setRootConfirm: (value) => {
							touch();
							setRootConfirm(value);
						},
					});
					break;
				case 'blocked':
					content = el(BlockedTab, { blocks, setBlocks });
					break;
				case 'site':
					content = el(SiteTab, {
						site,
						updateSite,
						onDisable: () => setDisabling(true),
					});
					break;
				case 'maintenance':
					content = el(MaintenanceTab);
					break;
				case 'revisions':
					content = el(RevisionsTab);
					break;
				default:
					content = el(TypesTab, { types, updateType });
			}
			return el(
				Fragment,
				null,
				content,
				CONFIG_TABS.includes(tab.name)
					? el(
							'div',
							{ className: 'tcc-admin__savebar' },
							el(
								Button,
								{
									type: 'submit',
									variant: 'primary',
									isBusy: submitting,
									disabled: submitting,
								},
								__('Save configuration', 'post-404-shield')
							),
							el(
								'span',
								{
									className: 'tcc-admin__savebar-note',
									'aria-live': 'polite',
								},
								dirty
									? __(
											'You have unsaved changes.',
											'post-404-shield'
										)
									: __(
											'Changes on these tabs take effect when you save.',
											'post-404-shield'
										)
							)
						)
					: null
			);
		};

		return el(
			Fragment,
			null,
			el(Notices),
			el(StatusTiles),
			el(
				'form',
				{
					method: 'post',
					action: data.urls.adminPost,
					className: 'post-shield-form',
					onSubmit: (event) => {
						// A portal (the disable modal) bubbles its submit through here.
						if (event.target !== event.currentTarget) {
							return;
						}
						setSubmitting(true);
					},
				},
				el(HiddenFields, {
					site,
					types,
					blocks,
					operator,
					rootConfirm,
				}),
				el(
					TabPanel,
					{
						className: 'post-shield-tabs',
						tabs,
						initialTabName: initialTab(tabs),
						onSelect: rememberTab,
					},
					renderTab
				)
			),
			disabling
				? el(DisableModal, { onClose: () => setDisabling(false) })
				: null
		);
	}

	root.innerHTML = '';
	if (createRoot) {
		createRoot(root).render(el(App));
	} else {
		render(el(App), root);
	}
})(window.wp, window.postShieldAdmin);
