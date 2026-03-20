import { defineConfig } from 'vite';
import dts from 'vite-plugin-dts';

export default defineConfig({
  plugins: [dts({ rollupTypes: false, outDir: 'dist' })],
  build: {
    lib: {
      entry: 'src/index.ts',
      name: 'Payswitch',
      formats: ['es', 'umd'],
      fileName: (format) => `payswitch.${format === 'es' ? 'mjs' : 'js'}`,
    },
  },
  test: {
    environment: 'happy-dom',
  },
});
