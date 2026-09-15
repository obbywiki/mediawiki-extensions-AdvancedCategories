import { dirname, resolve } from 'path';
import { fileURLToPath } from 'url';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

const root_dir = dirname( fileURLToPath( import.meta.url ) );

export default defineConfig( {
	plugins: [ vue() ],
	build: {
		outDir: 'resources/dist',
		emptyOutDir: true,
		lib: {
			entry: resolve( root_dir, 'src/app/main.ts' ),
			formats: [ 'cjs' ],
			fileName: () => 'advancedcategories-app.js'
		},
		rollupOptions: {
			external: [ 'vue', '@wikimedia/codex' ],
			output: {
				exports: 'none',
				paths: {
					'@wikimedia/codex': './codex.js'
				}
			}
		}
	}
} );
