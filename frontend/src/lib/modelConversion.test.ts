import { describe, expect, it } from 'vitest';
import { validateClientModelFiles } from './modelConversion';

const tenMb = 10 * 1024 * 1024;

describe('on-device model conversion validation', () => {
  it('accepts one model with its material and texture files', () => {
    const model = new File(['o chair'], 'chair.obj', { type: 'text/plain' });
    const files = [
      model,
      new File(['newmtl wood'], 'chair.mtl', { type: 'text/plain' }),
      new File(['texture'], 'wood.png', { type: 'image/png' }),
    ];

    expect(validateClientModelFiles(files, tenMb)).toBe(model);
  });

  it('rejects SketchUp instead of pretending open-source Assimp can import it', () => {
    const files = [new File(['skp'], 'chair.skp')];

    expect(() => validateClientModelFiles(files, tenMb)).toThrow(/Unsupported file/);
  });

  it('rejects multiple main models and oversized selections', () => {
    const twoModels = [
      new File(['a'], 'chair.fbx'),
      new File(['b'], 'table.stl'),
    ];
    expect(() => validateClientModelFiles(twoModels, tenMb)).toThrow(/one main model/i);

    const largeModel = new File([new Uint8Array(16)], 'chair.obj');
    expect(() => validateClientModelFiles([largeModel], 8)).toThrow(/exceed/i);
  });

  it('rejects duplicate and unsafe companion paths', () => {
    const duplicateFiles = [
      new File(['o chair'], 'chair.obj'),
      new File(['a'], 'wood.png'),
      new File(['b'], 'wood.png'),
    ];
    expect(() => validateClientModelFiles(duplicateFiles, tenMb)).toThrow(/same name/i);

    const unsafeModel = new File(['o chair'], '../chair.obj');
    expect(() => validateClientModelFiles([unsafeModel], tenMb)).toThrow(/unsafe/i);
  });
});
