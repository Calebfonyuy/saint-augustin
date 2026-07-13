// SlideDto validation — the slide wire shape must accept scripture
// placeholder slides (FR-PL-2, Stage 5) without breaking the existing
// song shape. Real scripture rendering lands in Stage 7; here we only
// verify the DTO tolerates the new `kind`/`reference` fields.
import { plainToInstance } from 'class-transformer';
import { validateSync } from 'class-validator';
import { SlideDto } from '../slide.dto';

function errorsFor(raw: Record<string, unknown>): string[] {
  const dto = plainToInstance(SlideDto, raw);
  return validateSync(dto).map((e) => e.property);
}

const songSlide = {
  id: 'sl-1',
  itemIndex: 0,
  slideIndex: 0,
  songTitle: 'Amazing Grace',
  section: 'Verse 1',
  body: 'Amazing grace...',
};

describe('SlideDto', () => {
  it('accepts a song slide with no kind (backward compatible)', () => {
    expect(errorsFor(songSlide)).toEqual([]);
  });

  it('accepts a scripture placeholder slide', () => {
    expect(
      errorsFor({
        ...songSlide,
        songTitle: 'Jean 3:16',
        body: 'Jean 3:16',
        kind: 'scripture',
        reference: 'Jean 3:16 · Segond 1910',
      }),
    ).toEqual([]);
  });

  it('rejects an unknown kind', () => {
    expect(errorsFor({ ...songSlide, kind: 'sermon' })).toContain('kind');
  });
});
