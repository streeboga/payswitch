import { h } from 'preact';
import { useEffect, useRef } from 'preact/hooks';
import type { FormRedirectData, WidgetTranslations } from '../types';

interface FormRedirectProps {
  data: FormRedirectData;
  t: WidgetTranslations;
}

const s = {
  container: {
    padding: '24px',
    textAlign: 'center' as const,
    color: '#666',
  },
  spinner: {
    width: '24px',
    height: '24px',
    margin: '0 auto 12px',
    border: '3px solid #e0e0e0',
    borderTopColor: '#0066ff',
    borderRadius: '50%',
    animation: 'ps-spin 0.6s linear infinite',
  },
};

export function FormRedirect({ data, t }: FormRedirectProps) {
  const formRef = useRef<HTMLFormElement>(null);

  useEffect(() => {
    if (formRef.current) {
      formRef.current.submit();
    }
  }, []);

  return (
    <div style={s.container}>
      <div style={s.spinner} />
      <div>{t.formRedirecting}</div>
      <form
        ref={formRef}
        action={data.url}
        method={data.method}
        style={{ display: 'none' }}
      >
        {Object.entries(data.params).map(([name, value]) => (
          <input type="hidden" name={name} value={value} key={name} />
        ))}
      </form>
    </div>
  );
}
