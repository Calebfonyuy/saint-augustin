// Slide DTO — the unit broadcast to projection displays.
// Slides are precomputed by the controller (frontend) from a playlist's
// items + ChordPro lyrics and pushed up at session creation. The backend
// itself does no parsing — its job is dispatch + state.
import { IsArray, IsIn, IsInt, IsOptional, IsString, Min } from 'class-validator';

export class SlideDto {
  /** Stable id, unique within the session (e.g. `${itemId}-v1`). */
  @IsString()
  id!: string;

  /**
   * What the parent playlist item is (FR-PL-2). Defaults to 'song' when
   * omitted so existing controllers keep working unchanged. Scripture
   * readings load as placeholder slides until Stage 7 adds real rendering;
   * this discriminator lets the display switch layout without re-parsing.
   */
  @IsOptional()
  @IsIn(['song', 'scripture'])
  kind?: 'song' | 'scripture';

  /**
   * For a scripture slide, the resolved reference label (e.g.
   * "Jean 3:16 · Segond 1910"). Null/omitted for song slides.
   */
  @IsOptional()
  @IsString()
  reference?: string | null;

  /**
   * Index of the parent playlist item. Used by jump-to-song to land on the
   * first slide of a given song. -1 for slides that aren't tied to a song
   * (e.g. a "blank" or "title" slide that the controller may add later).
   */
  @IsInt()
  itemIndex!: number;

  /** Position within the song's slide group, 0-based. */
  @IsInt()
  @Min(0)
  slideIndex!: number;

  /** Title shown in projection chrome and in the controller pane. */
  @IsString()
  songTitle!: string;

  /** Optional ChordPro section label: "Verse 1", "Chorus", "Bridge", … */
  @IsOptional()
  @IsString()
  section?: string | null;

  /**
   * The lyrics text for this slide. Already stripped of chord tokens and
   * ChordPro directives by the splitter. Plain text, newline-separated.
   */
  @IsString()
  body!: string;
}

/** Wrapper used when validating arrays. */
export class SlideListDto {
  @IsArray()
  slides!: SlideDto[];
}
