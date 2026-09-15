declare module '*.vue' {
	import type { DefineComponent } from 'vue';
	const component: DefineComponent<Record<string, unknown>, Record<string, unknown>, unknown>;
	export default component;
}

declare module '@wikimedia/codex' {
	import type { DefineComponent } from 'vue';
	import type { Icon } from '@wikimedia/codex-icons';

	export const CdxIcon: DefineComponent<{
		icon: Icon;
		size?: string;
	}>;
}
