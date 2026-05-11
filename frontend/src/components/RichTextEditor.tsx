import ReactQuill from 'react-quill-new';
import 'react-quill-new/dist/quill.snow.css';

interface RichTextEditorProps {
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
}

const modules = {
  toolbar: [
    [{ header: [1, 2, 3, false] }],
    ['bold', 'italic', 'underline', 'strike'],
    [{ color: [] }, { background: [] }],
    [{ list: 'ordered' }, { list: 'bullet' }],
    [{ align: [] }],
    ['link', 'image', 'video'],
    ['clean'],
  ],
};

const formats = [
  'header',
  'bold',
  'italic',
  'underline',
  'strike',
  'color',
  'background',
  'list',
  'align',
  'link',
  'image',
  'video',
];

export default function RichTextEditor({ value, onChange, placeholder }: RichTextEditorProps) {
  return (
    <div className="rich-text-editor">
      <ReactQuill
        theme="snow"
        value={value}
        onChange={onChange}
        modules={modules}
        formats={formats}
        placeholder={placeholder}
      />
      <style>{`
        /* Light mode (default) */
        .rich-text-editor .ql-container {
          min-height: 150px;
          font-size: 14px;
          border-bottom-left-radius: 8px;
          border-bottom-right-radius: 8px;
          background: #ffffff;
          border-color: #e5e7eb;
        }
        .rich-text-editor .ql-toolbar {
          border-top-left-radius: 8px;
          border-top-right-radius: 8px;
          background: #f9fafb;
          border-color: #e5e7eb;
        }
        .rich-text-editor .ql-editor {
          min-height: 120px;
          color: #111827;
        }
        .rich-text-editor .ql-editor.ql-blank::before {
          color: #9ca3af;
          font-style: normal;
        }
        .rich-text-editor .ql-stroke {
          stroke: #374151 !important;
        }
        .rich-text-editor .ql-fill {
          fill: #374151 !important;
        }
        .rich-text-editor .ql-picker {
          color: #374151 !important;
        }
        .rich-text-editor .ql-picker-label {
          color: #374151 !important;
        }
        .rich-text-editor .ql-picker-options {
          background: #ffffff;
          border-color: #e5e7eb;
        }
        .rich-text-editor .ql-picker-item {
          color: #374151;
        }
        .rich-text-editor button:hover .ql-stroke,
        .rich-text-editor .ql-picker-label:hover .ql-stroke {
          stroke: #22c55e !important;
        }
        .rich-text-editor button:hover .ql-fill,
        .rich-text-editor .ql-picker-label:hover .ql-fill {
          fill: #22c55e !important;
        }
        .rich-text-editor button.ql-active .ql-stroke {
          stroke: #22c55e !important;
        }
        .rich-text-editor button.ql-active .ql-fill {
          fill: #22c55e !important;
        }
        .rich-text-editor .ql-toolbar button:hover,
        .rich-text-editor .ql-toolbar button:focus {
          color: #22c55e;
        }

        /* Dark mode */
        .dark .rich-text-editor .ql-container {
          background: #18181b;
          border-color: #3f3f46;
        }
        .dark .rich-text-editor .ql-toolbar {
          background: #18181b;
          border-color: #3f3f46;
        }
        .dark .rich-text-editor .ql-editor {
          color: #f9fafb;
        }
        .dark .rich-text-editor .ql-editor.ql-blank::before {
          color: #71717a;
        }
        .dark .rich-text-editor .ql-stroke {
          stroke: #d1d5db !important;
        }
        .dark .rich-text-editor .ql-fill {
          fill: #d1d5db !important;
        }
        .dark .rich-text-editor .ql-picker {
          color: #d1d5db !important;
        }
        .dark .rich-text-editor .ql-picker-label {
          color: #d1d5db !important;
        }
        .dark .rich-text-editor .ql-picker-options {
          background: #18181b;
          border-color: #3f3f46;
        }
        .dark .rich-text-editor .ql-picker-item {
          color: #d1d5db;
        }
        .dark .rich-text-editor .ql-picker-item:hover {
          color: #ffffff;
          background: #27272a;
        }
        .dark .rich-text-editor button:hover .ql-stroke,
        .dark .rich-text-editor .ql-picker-label:hover .ql-stroke {
          stroke: #a78bfa !important;
        }
        .dark .rich-text-editor button:hover .ql-fill,
        .dark .rich-text-editor .ql-picker-label:hover .ql-fill {
          fill: #a78bfa !important;
        }
        .dark .rich-text-editor button.ql-active .ql-stroke {
          stroke: #a78bfa !important;
        }
        .dark .rich-text-editor button.ql-active .ql-fill {
          fill: #a78bfa !important;
        }
        .dark .rich-text-editor .ql-toolbar button:hover,
        .dark .rich-text-editor .ql-toolbar button:focus {
          color: #a78bfa;
        }
      `}</style>
    </div>
  );
}
