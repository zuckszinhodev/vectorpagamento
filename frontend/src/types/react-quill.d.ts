declare module 'react-quill' {
  import * as React from 'react';

  export interface QuillOptions {
    debug?: string;
    modules?: Record<string, unknown>;
    placeholder?: string;
    readOnly?: boolean;
    theme?: string;
    formats?: string[];
  }

  export interface ReactQuillProps {
    id?: string;
    className?: string;
    theme?: string;
    style?: React.CSSProperties;
    readOnly?: boolean;
    value?: string;
    defaultValue?: string;
    placeholder?: string;
    tabIndex?: number;
    bounds?: string | HTMLElement;
    scrollingContainer?: string | HTMLElement;
    onChange?: (
      content: string,
      delta: unknown,
      source: string,
      editor: unknown
    ) => void;
    onChangeSelection?: (
      range: unknown,
      source: string,
      editor: unknown
    ) => void;
    onFocus?: (
      range: unknown,
      source: string,
      editor: unknown
    ) => void;
    onBlur?: (
      previousRange: unknown,
      source: string,
      editor: unknown
    ) => void;
    onKeyPress?: React.EventHandler<unknown>;
    onKeyDown?: React.EventHandler<unknown>;
    onKeyUp?: React.EventHandler<unknown>;
    formats?: string[];
    modules?: Record<string, unknown>;
    preserveWhitespace?: boolean;
    children?: React.ReactElement<unknown>;
  }

  export default class ReactQuill extends React.Component<ReactQuillProps> {
    focus(): void;
    blur(): void;
    getEditor(): unknown;
  }
}

declare module 'react-quill/dist/quill.snow.css';
