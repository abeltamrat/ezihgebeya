/// <reference lib="webworker" />

// AssimpJS does not publish TypeScript declarations; the runtime shape is narrowed below.
// @ts-expect-error package has no declaration file
import assimpFactoryModule from 'assimpjs';
import assimpWasmUrl from 'assimpjs/dist/assimpjs.wasm?url';

export {};

interface AssimpFileList {
  AddFile(name: string, content: Uint8Array): void;
}

interface AssimpResultFile {
  GetPath(): string;
  GetContent(): Uint8Array;
}

interface AssimpResult {
  IsSuccess(): boolean;
  FileCount(): number;
  GetErrorCode(): number | string;
  GetFile(index: number): AssimpResultFile;
}

interface AssimpModule {
  FileList: new () => AssimpFileList;
  ConvertFileList(files: AssimpFileList, target: string): AssimpResult;
}

type AssimpFactory = (options?: {
  locateFile?: (path: string, prefix: string) => string;
}) => Promise<AssimpModule>;

const scope = self as DedicatedWorkerGlobalScope;
let assimpModule: Promise<AssimpModule> | null = null;

function loadAssimp(): Promise<AssimpModule> {
  if (assimpModule) return assimpModule;
  const assimpFactory = assimpFactoryModule as AssimpFactory;
  assimpModule = assimpFactory({
    locateFile: (path) => path.endsWith('.wasm') ? assimpWasmUrl : path,
  }).catch((error) => {
    assimpModule = null;
    throw error;
  });
  return assimpModule;
}

scope.onmessage = async (event: MessageEvent<{
  files: Array<{ name: string; buffer: ArrayBuffer }>;
}>) => {
  try {
    scope.postMessage({ type: 'stage', stage: 'loading' });
    const ajs = await loadAssimp();
    scope.postMessage({ type: 'stage', stage: 'converting' });

    const fileList = new ajs.FileList();
    for (const file of event.data.files) {
      fileList.AddFile(file.name, new Uint8Array(file.buffer));
    }
    const result = ajs.ConvertFileList(fileList, 'glb2');
    if (!result.IsSuccess() || result.FileCount() < 1) {
      throw new Error(`Assimp could not import this model (error ${result.GetErrorCode()}).`);
    }

    let output: AssimpResultFile | null = null;
    for (let i = 0; i < result.FileCount(); i++) {
      const candidate = result.GetFile(i);
      if (candidate.GetPath().toLowerCase().endsWith('.glb')) {
        output = candidate;
        break;
      }
    }
    if (!output) throw new Error('The converter did not produce a GLB file.');

    // Copy out of WebAssembly memory before transferring the buffer.
    const content = output.GetContent();
    const copy = new Uint8Array(content.byteLength);
    copy.set(content);
    if (copy.byteLength < 12
      || copy[0] !== 0x67 || copy[1] !== 0x6c || copy[2] !== 0x54 || copy[3] !== 0x46) {
      throw new Error('The converter produced an invalid GLB file.');
    }
    scope.postMessage({ type: 'success', buffer: copy.buffer }, [copy.buffer]);
  } catch (error) {
    scope.postMessage({
      type: 'error',
      message: error instanceof Error ? error.message : 'Unknown browser conversion error.',
    });
  }
};
