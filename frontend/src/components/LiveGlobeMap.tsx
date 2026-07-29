import { useEffect, useMemo, useRef, useState } from 'react';
import * as THREE from 'three';
import {
  ArrowPathIcon,
  BoltIcon,
  ChevronLeftIcon,
  ServerStackIcon,
  ShoppingBagIcon,
  XMarkIcon,
} from '@heroicons/react/24/outline';
import clsx from 'clsx';
import { api } from '@/lib/api';
import { isRealtimeEnabled, useChannelEvents } from '@/hooks/useWebSocket';

interface LiveGlobeMapProps {
  accountId?: string | null;
}

type LiveEventKind = 'checkout' | 'api';

interface LiveMapEvent {
  id: string;
  kind: LiveEventKind;
  label: string;
  location: string;
  country?: string;
  lat: number;
  lng: number;
  at: string;
}

interface LiveMapSummary {
  revenueToday: number;
  salesToday: number;
  visitsNow: number;
}

type WorldGeoJson = {
  features?: Array<{
    geometry?: {
      type?: 'Polygon' | 'MultiPolygon';
      coordinates?: any;
    };
  }>;
};

const demoLocations: Array<Omit<LiveMapEvent, 'id' | 'at'>> = [
  { kind: 'checkout', label: 'Checkout aberto', location: 'Sao Paulo, BR', country: 'BR', lat: -23.55, lng: -46.63 },
  { kind: 'api', label: 'API consultada', location: 'Rio de Janeiro, BR', country: 'BR', lat: -22.9, lng: -43.2 },
  { kind: 'checkout', label: 'Checkout aberto', location: 'Lisboa, PT', country: 'PT', lat: 38.72, lng: -9.14 },
  { kind: 'checkout', label: 'Pagamento iniciado', location: 'Miami, US', country: 'US', lat: 25.76, lng: -80.19 },
  { kind: 'api', label: 'Webhook recebido', location: 'Buenos Aires, AR', country: 'AR', lat: -34.6, lng: -58.38 },
  { kind: 'checkout', label: 'Checkout aberto', location: 'Bogota, CO', country: 'CO', lat: 4.71, lng: -74.07 },
  { kind: 'api', label: 'API consultada', location: 'Mexico City, MX', country: 'MX', lat: 19.43, lng: -99.13 },
  { kind: 'checkout', label: 'Checkout aberto', location: 'London, UK', country: 'UK', lat: 51.5, lng: -0.12 },
];

const currencyFormatter = new Intl.NumberFormat('pt-BR', {
  style: 'currency',
  currency: 'BRL',
});

function createEvent(overrides?: Partial<LiveMapEvent>): LiveMapEvent {
  const base = demoLocations[Math.floor(Math.random() * demoLocations.length)];
  return {
    ...base,
    id: `${Date.now()}-${Math.random().toString(16).slice(2)}`,
    at: new Date().toISOString(),
    ...overrides,
  };
}

function normalizeEvent(data: any): LiveMapEvent | null {
  const lat = Number(data?.lat ?? data?.latitude);
  const lng = Number(data?.lng ?? data?.longitude);

  if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
    return null;
  }

  const kind = data?.kind === 'api' || data?.type === 'api' ? 'api' : 'checkout';

  return {
    id: String(data?.id || `${Date.now()}-${Math.random().toString(16).slice(2)}`),
    kind,
    label: String(data?.label || (kind === 'api' ? 'API consultada' : 'Checkout aberto')),
    location: String(data?.location || data?.city || data?.country || 'Localizacao detectada'),
    country: data?.country ? String(data.country) : undefined,
    lat,
    lng,
    at: String(data?.at || data?.created_at || new Date().toISOString()),
  };
}

function latLngToVector3(lat: number, lng: number, radius: number) {
  const phi = THREE.MathUtils.degToRad(90 - lat);
  const theta = THREE.MathUtils.degToRad(lng + 180);

  return new THREE.Vector3(
    -radius * Math.sin(phi) * Math.cos(theta),
    radius * Math.cos(phi),
    radius * Math.sin(phi) * Math.sin(theta)
  );
}

function createMarkerTexture(color: string) {
  const canvas = document.createElement('canvas');
  canvas.width = 128;
  canvas.height = 128;
  const ctx = canvas.getContext('2d');

  if (!ctx) return null;

  const gradient = ctx.createRadialGradient(64, 64, 4, 64, 64, 56);
  gradient.addColorStop(0, color);
  gradient.addColorStop(0.28, color);
  gradient.addColorStop(0.42, `${color}cc`);
  gradient.addColorStop(0.72, `${color}38`);
  gradient.addColorStop(1, `${color}00`);
  ctx.fillStyle = gradient;
  ctx.beginPath();
  ctx.arc(64, 64, 58, 0, Math.PI * 2);
  ctx.fill();

  ctx.fillStyle = '#ffffff';
  ctx.globalAlpha = 0.95;
  ctx.beginPath();
  ctx.arc(64, 64, 15, 0, Math.PI * 2);
  ctx.fill();

  ctx.globalAlpha = 1;
  ctx.fillStyle = color;
  ctx.beginPath();
  ctx.arc(64, 64, 10, 0, Math.PI * 2);
  ctx.fill();

  const texture = new THREE.CanvasTexture(canvas);
  texture.colorSpace = THREE.SRGBColorSpace;
  return texture;
}

function disposeObject3D(object: THREE.Object3D) {
  object.traverse((child) => {
    if (child instanceof THREE.Mesh || child instanceof THREE.Points || child instanceof THREE.Sprite) {
      child.geometry?.dispose();
      const material = child.material;

      if (Array.isArray(material)) {
        material.forEach((item) => {
          item.map?.dispose();
          item.dispose();
        });
      } else {
        material.map?.dispose();
        material.dispose();
      }
    }
  });
}

function drawCountryPath(
  ctx: CanvasRenderingContext2D,
  rings: number[][][],
  width: number,
  height: number
) {
  rings.forEach((ring) => {
    if (!Array.isArray(ring) || ring.length === 0) return;

    ring.forEach((point, index) => {
      const [lng, lat] = point;
      const x = ((lng + 180) / 360) * width;
      const y = ((90 - lat) / 180) * height;

      if (index === 0) {
        ctx.moveTo(x, y);
      } else {
        ctx.lineTo(x, y);
      }
    });
    ctx.closePath();
  });
}

function drawCountries(ctx: CanvasRenderingContext2D, geoJson: WorldGeoJson, width: number, height: number) {
  const landPalette = ['#284f36', '#315d3c', '#45643d', '#665d3c', '#7b6a43', '#596f4c'];

  geoJson.features?.forEach((feature, index) => {
    const geometry = feature.geometry;
    if (!geometry?.coordinates) return;

    ctx.fillStyle = landPalette[index % landPalette.length];
    ctx.strokeStyle = 'rgba(205, 230, 213, 0.48)';
    ctx.lineWidth = 0.72;
    ctx.beginPath();

    if (geometry.type === 'Polygon') {
      drawCountryPath(ctx, geometry.coordinates, width, height);
    }

    if (geometry.type === 'MultiPolygon') {
      geometry.coordinates.forEach((polygon: number[][][]) => drawCountryPath(ctx, polygon, width, height));
    }

    ctx.fill('evenodd');
    ctx.stroke();
  });
}

function createEarthTexture(worldGeoJson?: WorldGeoJson | null) {
  const canvas = document.createElement('canvas');
  canvas.width = 2048;
  canvas.height = 1024;
  const ctx = canvas.getContext('2d');

  if (!ctx) return null;

  const width = canvas.width;
  const height = canvas.height;
  const ocean = ctx.createLinearGradient(0, 0, width, height);
  ocean.addColorStop(0, '#071426');
  ocean.addColorStop(0.48, '#0b2d54');
  ocean.addColorStop(1, '#03101f');
  ctx.fillStyle = ocean;
  ctx.fillRect(0, 0, width, height);

  ctx.save();
  ctx.globalAlpha = 0.95;
  if (worldGeoJson?.features?.length) {
    drawCountries(ctx, worldGeoJson, width, height);
  } else {
    ctx.fillStyle = '#315d3c';
    ctx.fillRect(width * 0.18, height * 0.28, width * 0.12, height * 0.16);
    ctx.fillRect(width * 0.45, height * 0.28, width * 0.14, height * 0.2);
    ctx.fillRect(width * 0.67, height * 0.25, width * 0.2, height * 0.22);
  }
  ctx.restore();

  ctx.save();
  ctx.globalAlpha = 0.16;
  const glow = ctx.createRadialGradient(width * 0.58, height * 0.42, 0, width * 0.58, height * 0.42, width * 0.36);
  glow.addColorStop(0, 'rgba(125, 211, 252, 0.55)');
  glow.addColorStop(0.45, 'rgba(34, 197, 94, 0.18)');
  glow.addColorStop(1, 'rgba(125, 211, 252, 0)');
  ctx.fillStyle = glow;
  ctx.fillRect(0, 0, width, height);
  ctx.restore();

  const texture = new THREE.CanvasTexture(canvas);
  texture.colorSpace = THREE.SRGBColorSpace;
  texture.anisotropy = 8;
  return texture;
}

function GlobeCanvas({
  events,
  active,
  worldGeoJson,
}: {
  events: LiveMapEvent[];
  active: boolean;
  worldGeoJson?: WorldGeoJson | null;
}) {
  const containerRef = useRef<HTMLDivElement>(null);
  const eventGroupRef = useRef<THREE.Group | null>(null);
  const dragStateRef = useRef({
    isDragging: false,
    lastX: 0,
    lastY: 0,
    autoRotate: true,
    resumeAt: 0,
  });

  useEffect(() => {
    if (!active || !containerRef.current) return;

    const container = containerRef.current;
    const scene = new THREE.Scene();
    scene.fog = new THREE.FogExp2(0x050713, 0.035);

    const camera = new THREE.PerspectiveCamera(42, 1, 0.1, 100);
    camera.position.set(0, 0.2, 8.2);

    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    container.appendChild(renderer.domElement);

    const globeGroup = new THREE.Group();
    globeGroup.rotation.y = -0.65;
    globeGroup.rotation.x = 0.08;
    scene.add(globeGroup);

    const earthTexture = createEarthTexture(worldGeoJson);
    const globe = new THREE.Mesh(
      new THREE.SphereGeometry(2.25, 96, 96),
      new THREE.MeshPhongMaterial({
        map: earthTexture || undefined,
        color: earthTexture ? 0xffffff : 0x1b6b7a,
        shininess: 18,
        specular: new THREE.Color(0x2b71ff),
      })
    );
    globeGroup.add(globe);

    const atmosphere = new THREE.Mesh(
      new THREE.SphereGeometry(2.43, 96, 96),
      new THREE.MeshBasicMaterial({
        color: 0x65f4c4,
        transparent: true,
        opacity: 0.12,
        side: THREE.BackSide,
      })
    );
    globeGroup.add(atmosphere);

    const eventGroup = new THREE.Group();
    eventGroupRef.current = eventGroup;
    globeGroup.add(eventGroup);

    const starsGeometry = new THREE.BufferGeometry();
    const starPositions = new Float32Array(900);
    for (let index = 0; index < starPositions.length; index += 3) {
      starPositions[index] = (Math.random() - 0.5) * 22;
      starPositions[index + 1] = (Math.random() - 0.5) * 13;
      starPositions[index + 2] = -Math.random() * 8 - 2;
    }
    starsGeometry.setAttribute('position', new THREE.BufferAttribute(starPositions, 3));
    const stars = new THREE.Points(
      starsGeometry,
      new THREE.PointsMaterial({
        color: 0xdde9ff,
        size: 0.018,
        transparent: true,
        opacity: 0.78,
      })
    );
    scene.add(stars);

    scene.add(new THREE.AmbientLight(0x94baff, 0.8));
    const sun = new THREE.DirectionalLight(0xffffff, 2.5);
    sun.position.set(5, 3, 4);
    scene.add(sun);
    const rim = new THREE.DirectionalLight(0x4df2b2, 1.1);
    rim.position.set(-4, -2, -3);
    scene.add(rim);
    const backLight = new THREE.PointLight(0x3b82f6, 4.2, 18);
    backLight.position.set(-2.8, 1.2, -3.2);
    scene.add(backLight);

    const resize = () => {
      const width = container.clientWidth || window.innerWidth;
      const height = container.clientHeight || window.innerHeight;
      renderer.setSize(width, height);
      camera.aspect = width / height;
      camera.position.z = width < 700 ? 8.8 : 7.2;
      camera.updateProjectionMatrix();
    };

    resize();
    window.addEventListener('resize', resize);

    const onPointerDown = (event: PointerEvent) => {
      dragStateRef.current.isDragging = true;
      dragStateRef.current.autoRotate = false;
      dragStateRef.current.lastX = event.clientX;
      dragStateRef.current.lastY = event.clientY;
      dragStateRef.current.resumeAt = 0;
      renderer.domElement.setPointerCapture(event.pointerId);
      renderer.domElement.style.cursor = 'grabbing';
    };

    const onPointerMove = (event: PointerEvent) => {
      if (!dragStateRef.current.isDragging) return;

      const deltaX = event.clientX - dragStateRef.current.lastX;
      const deltaY = event.clientY - dragStateRef.current.lastY;
      dragStateRef.current.lastX = event.clientX;
      dragStateRef.current.lastY = event.clientY;

      globeGroup.rotation.y += deltaX * 0.006;
      globeGroup.rotation.x += deltaY * 0.004;
      globeGroup.rotation.x = THREE.MathUtils.clamp(globeGroup.rotation.x, -1.05, 1.05);
    };

    const onPointerUp = (event: PointerEvent) => {
      dragStateRef.current.isDragging = false;
      dragStateRef.current.resumeAt = Date.now() + 5500;
      renderer.domElement.releasePointerCapture(event.pointerId);
      renderer.domElement.style.cursor = 'grab';
    };

    const onPointerLeave = () => {
      if (!dragStateRef.current.isDragging) return;
      dragStateRef.current.isDragging = false;
      dragStateRef.current.resumeAt = Date.now() + 5500;
      renderer.domElement.style.cursor = 'grab';
    };

    renderer.domElement.style.cursor = 'grab';
    renderer.domElement.style.touchAction = 'none';
    renderer.domElement.addEventListener('pointerdown', onPointerDown);
    renderer.domElement.addEventListener('pointermove', onPointerMove);
    renderer.domElement.addEventListener('pointerup', onPointerUp);
    renderer.domElement.addEventListener('pointercancel', onPointerUp);
    renderer.domElement.addEventListener('pointerleave', onPointerLeave);

    let animationFrame = 0;
    const markerPosition = new THREE.Vector3();
    const markerNormal = new THREE.Vector3();
    const cameraDirection = new THREE.Vector3();

    const animate = () => {
      animationFrame = window.requestAnimationFrame(animate);
      if (!dragStateRef.current.autoRotate && dragStateRef.current.resumeAt && Date.now() > dragStateRef.current.resumeAt) {
        dragStateRef.current.autoRotate = true;
        dragStateRef.current.resumeAt = 0;
      }

      if (dragStateRef.current.autoRotate && !dragStateRef.current.isDragging) {
        globeGroup.rotation.y += 0.00145;
      }
      stars.rotation.y -= 0.00025;
      stars.rotation.x += 0.00012;

      eventGroup.children.forEach((child, index) => {
        const scale = 1 + Math.sin(Date.now() * 0.005 + index) * 0.18;
        child.scale.setScalar(scale);

        child.getWorldPosition(markerPosition);
        markerNormal.copy(markerPosition).normalize();
        cameraDirection.copy(camera.position).sub(markerPosition).normalize();
        child.visible = markerNormal.dot(cameraDirection) > 0.08;
      });

      renderer.render(scene, camera);
    };
    animate();

    return () => {
      window.cancelAnimationFrame(animationFrame);
      window.removeEventListener('resize', resize);
      renderer.domElement.removeEventListener('pointerdown', onPointerDown);
      renderer.domElement.removeEventListener('pointermove', onPointerMove);
      renderer.domElement.removeEventListener('pointerup', onPointerUp);
      renderer.domElement.removeEventListener('pointercancel', onPointerUp);
      renderer.domElement.removeEventListener('pointerleave', onPointerLeave);
      container.removeChild(renderer.domElement);
      earthTexture?.dispose();
      starsGeometry.dispose();
      scene.traverse((object) => {
        if (object instanceof THREE.Mesh || object instanceof THREE.Points) {
          object.geometry.dispose();
          const material = object.material;
          if (Array.isArray(material)) {
            material.forEach((item) => item.dispose());
          } else {
            material.dispose();
          }
        }
      });
      renderer.dispose();
      eventGroupRef.current = null;
    };
  }, [active, worldGeoJson]);

  useEffect(() => {
    const group = eventGroupRef.current;
    if (!group) return;

    group.children.forEach(disposeObject3D);
    group.clear();
    events.slice(0, 34).forEach((event) => {
      const marker = new THREE.Group();
      const position = latLngToVector3(event.lat, event.lng, 2.48);
      marker.position.copy(position);
      marker.quaternion.setFromUnitVectors(new THREE.Vector3(0, 0, 1), position.clone().normalize());

      const color = event.kind === 'api' ? '#7dd3fc' : '#5ee0a3';
      const texture = createMarkerTexture(color);
      if (!texture) return;

      const pin = new THREE.Sprite(
        new THREE.SpriteMaterial({
          map: texture,
          transparent: true,
          depthTest: true,
          depthWrite: false,
        })
      );
      const size = event.kind === 'api' ? 0.26 : 0.31;
      pin.scale.set(size, size, 1);
      marker.add(pin);

      group.add(marker);
    });
  }, [events, active, worldGeoJson]);

  return <div ref={containerRef} className="absolute inset-0" />;
}

export default function LiveGlobeMap({ accountId }: LiveGlobeMapProps) {
  const [isOpen, setIsOpen] = useState(false);
  const [events, setEvents] = useState<LiveMapEvent[]>(() => [
    createEvent({ location: 'Sao Paulo, BR', lat: -23.55, lng: -46.63 }),
    createEvent({ kind: 'api', location: 'Rio de Janeiro, BR', lat: -22.9, lng: -43.2 }),
    createEvent({ location: 'Lisboa, PT', lat: 38.72, lng: -9.14 }),
  ]);
  const [summary, setSummary] = useState<LiveMapSummary>({
    revenueToday: 0,
    salesToday: 0,
    visitsNow: 0,
  });
  const [worldGeoJson, setWorldGeoJson] = useState<WorldGeoJson | null>(null);
  const realtimeAvailable = isRealtimeEnabled();

  const pushEvent = (incoming: any) => {
    const event = normalizeEvent(incoming);
    if (!event) return;

    setEvents((current) => [event, ...current].slice(0, 50));
    setSummary((current) => ({
      ...current,
      visitsNow: Math.max(current.visitsNow + 1, 1),
      salesToday: incoming?.sale ? current.salesToday + 1 : current.salesToday,
      revenueToday: incoming?.amount ? current.revenueToday + Number(incoming.amount) : current.revenueToday,
    }));
  };

  useChannelEvents(
    `private-account.${accountId}`,
    [
      { event: 'checkout.visit.created', callback: pushEvent },
      { event: 'api.request.created', callback: pushEvent },
    ],
    Boolean(accountId && isOpen)
  );

  useEffect(() => {
    if (!isOpen) return;

    let isMounted = true;
    fetch('/data/countries.geo.json')
      .then((response) => response.ok ? response.json() : null)
      .then((geoJson) => {
        if (isMounted && geoJson?.features?.length) {
          setWorldGeoJson(geoJson);
        }
      })
      .catch(() => undefined);

    api.get('/live-map/events')
      .then((response) => {
        if (!isMounted) return;
        const data = response.data?.data || {};
        const incomingEvents = Array.isArray(data.events) ? data.events.map(normalizeEvent).filter(Boolean) : [];
        if (incomingEvents.length > 0) {
          setEvents(incomingEvents.slice(0, 50));
        }
        if (data.summary) {
          setSummary({
            revenueToday: Number(data.summary.revenue_today || 0),
            salesToday: Number(data.summary.sales_today || 0),
            visitsNow: Number(data.summary.visits_now || incomingEvents.length || 0),
          });
        }
      })
      .catch(() => undefined);

    return () => {
      isMounted = false;
    };
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen || realtimeAvailable) return;

    const interval = window.setInterval(() => {
      setEvents((current) => [createEvent(), ...current].slice(0, 50));
      setSummary((current) => ({
        revenueToday: current.revenueToday,
        salesToday: current.salesToday,
        visitsNow: Math.min(current.visitsNow + 1 || 1, 99),
      }));
    }, 4200);

    return () => window.clearInterval(interval);
  }, [isOpen, realtimeAvailable]);

  const latestEvents = events.slice(0, 6);

  const totals = useMemo(() => {
    const checkoutCount = events.filter((event) => event.kind === 'checkout').length;
    const apiCount = events.filter((event) => event.kind === 'api').length;
    return {
      checkoutCount,
      apiCount,
      visitsNow: summary.visitsNow || events.length,
    };
  }, [events, summary.visitsNow]);

  return (
    <>
      <button
        onClick={() => setIsOpen(true)}
        className="fixed bottom-5 left-5 z-40 flex h-12 w-12 items-center justify-center rounded-full border border-emerald-300/40 bg-surface-elevated/90 text-emerald-500 shadow-float backdrop-blur-xl transition-all hover:-translate-y-0.5 hover:border-emerald-400 hover:bg-emerald-500 hover:text-white"
        title="Mapa global ao vivo"
      >
        <span className="live-globe-icon" aria-hidden="true">
          <svg className="live-globe-icon__svg" viewBox="0 0 32 32" fill="none">
            <defs>
              <clipPath id="live-globe-clip">
                <circle cx="16" cy="16" r="12" />
              </clipPath>
            </defs>
            <circle cx="16" cy="16" r="12" stroke="currentColor" strokeWidth="2.2" />
            <g clipPath="url(#live-globe-clip)">
              <g className="live-globe-icon__land">
                <path d="M0 10.4C3.1 8.4 7.4 8.7 9.7 11.2C11.6 13.2 9.8 15.1 12.2 16.7C14.9 18.6 19.3 16.5 21.2 19C22.6 20.9 20.9 23.9 18.1 25C14.5 26.4 9.2 24.2 7.9 20.6C7 18.2 8.9 16.7 7.5 14.8C5.9 12.7 1.9 13.3 0 10.4Z" />
                <path d="M24.4 7.8C28.3 6.4 32.9 7.4 34.7 10.4C36.2 12.8 33.8 14.1 35.3 16.4C36.7 18.5 40.2 18.4 41.6 21.2C42.7 23.5 41.1 26.2 38.8 27.1C35.6 28.5 31.7 25.9 31.1 22.9C30.5 20.3 32.7 19.1 31.7 16.9C30.7 14.6 26.9 14.7 24.9 12.2C23.5 10.5 23.2 8.7 24.4 7.8Z" />
              </g>
              <g className="live-globe-icon__grid">
                <path d="M4.5 16H27.5" stroke="currentColor" strokeWidth="1.45" strokeLinecap="round" opacity="0.64" />
                <path d="M6.4 10.8C11.5 12.15 20.5 12.15 25.6 10.8" stroke="currentColor" strokeWidth="1.15" strokeLinecap="round" opacity="0.32" />
                <path d="M6.4 21.2C11.5 19.85 20.5 19.85 25.6 21.2" stroke="currentColor" strokeWidth="1.15" strokeLinecap="round" opacity="0.32" />
                <ellipse className="live-globe-icon__meridian live-globe-icon__meridian--one" cx="16" cy="16" rx="3.6" ry="11.1" stroke="currentColor" strokeWidth="1.35" />
                <ellipse className="live-globe-icon__meridian live-globe-icon__meridian--two" cx="16" cy="16" rx="6.8" ry="11.2" stroke="currentColor" strokeWidth="1.18" />
                <ellipse className="live-globe-icon__meridian live-globe-icon__meridian--three" cx="16" cy="16" rx="9.5" ry="11.2" stroke="currentColor" strokeWidth="1.05" />
              </g>
            </g>
          </svg>
        </span>
        <span className="absolute right-1 top-1 h-2.5 w-2.5 rounded-full bg-emerald-400 ring-2 ring-surface-elevated" />
      </button>

      {isOpen && (
        <div className="fixed inset-0 z-[80] overflow-hidden bg-[#030611] text-white">
          <div className="pointer-events-none absolute left-1/2 top-1/2 h-[78vmin] w-[78vmin] -translate-x-1/2 -translate-y-1/2 rounded-full bg-emerald-400/10 blur-3xl" />
          <div className="pointer-events-none absolute left-[42%] top-[45%] h-[56vmin] w-[56vmin] -translate-x-1/2 -translate-y-1/2 rounded-full bg-sky-500/16 blur-3xl" />
          <div className="pointer-events-none absolute bottom-[-18%] left-[18%] h-[44vmin] w-[44vmin] rounded-full bg-teal-400/12 blur-3xl" />
          <GlobeCanvas events={events} active={isOpen} worldGeoJson={worldGeoJson} />
          <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_center,transparent_0%,rgba(3,6,17,0.12)_38%,rgba(3,6,17,0.92)_100%)]" />

          {/* Coming Soon Overlay — blurred background */}
          <div className="absolute inset-0 z-20 backdrop-blur-md bg-black/40" />
          <div className="absolute inset-0 z-30 flex items-center justify-center">
            <div className="flex flex-col items-center gap-4">
              <h3 className="text-xl font-bold tracking-wide text-white sm:text-2xl">Disponível em breve</h3>
              <p className="max-w-xs text-center text-sm text-white/60">
                O mapa global de acessos em tempo real estará disponível em uma próxima atualização.
              </p>
              <button
                onClick={() => setIsOpen(false)}
                className="mt-4 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-5 py-2 text-sm font-medium text-white backdrop-blur transition-colors hover:bg-white/20"
              >
                <ChevronLeftIcon className="h-4 w-4" />
                Voltar para Dashboard
              </button>
            </div>
          </div>

          <div className="absolute left-0 right-0 top-0 z-10 flex items-center justify-between px-5 py-4 sm:px-8">
            <div>
              <p className="text-[11px] font-semibold uppercase tracking-[0.34em] text-emerald-300">Mapa global</p>
              <h2 className="mt-1 text-xl font-semibold tracking-wide text-white sm:text-2xl">Acessos em tempo real</h2>
            </div>
            <button
              onClick={() => setIsOpen(false)}
              className="rounded-full border border-white/10 bg-white/5 p-2 text-white/60 backdrop-blur transition-colors hover:bg-white/10 hover:text-white"
              title="Fechar mapa global"
            >
              <XMarkIcon className="h-5 w-5" />
            </button>
          </div>

          <div className="absolute bottom-5 left-4 right-4 z-10 grid gap-3 sm:left-1/2 sm:right-auto sm:w-[720px] sm:-translate-x-1/2 sm:grid-cols-3">
            <MetricCard label="Venda total de hoje" value={currencyFormatter.format(summary.revenueToday / 100)} />
            <MetricCard label="Vendas hoje" value={String(summary.salesToday)} />
            <MetricCard label="Visitas agora" value={String(totals.visitsNow)} live />
          </div>

          <div className="absolute right-4 top-24 z-10 hidden w-80 rounded-card border border-white/10 bg-white/[0.06] p-4 shadow-2xl backdrop-blur-xl lg:block">
            <div className="mb-4 flex items-center justify-between">
              <div>
                <p className="text-sm font-semibold text-white">Feed ao vivo</p>
                <p className="text-xs text-white/45">
                  {realtimeAvailable ? 'Websocket conectado quando houver evento' : 'Modo desenvolvimento'}
                </p>
              </div>
              <ArrowPathIcon className={clsx('h-4 w-4 text-emerald-300', isOpen && 'animate-spin')} />
            </div>

            <div className="space-y-2">
              {latestEvents.map((event) => (
                <div key={event.id} className="flex items-center gap-3 rounded-lg border border-white/10 bg-black/20 px-3 py-2">
                  <div
                    className={clsx(
                      'flex h-8 w-8 items-center justify-center rounded-full',
                      event.kind === 'api' ? 'bg-sky-400/15 text-sky-300' : 'bg-emerald-400/15 text-emerald-300'
                    )}
                  >
                    {event.kind === 'api' ? <ServerStackIcon className="h-4 w-4" /> : <ShoppingBagIcon className="h-4 w-4" />}
                  </div>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-xs font-medium text-white">{event.label}</p>
                    <p className="truncate text-[11px] text-white/45">{event.location}</p>
                  </div>
                </div>
              ))}
            </div>

            <div className="mt-4 grid grid-cols-2 gap-2 text-center">
              <div className="rounded-lg border border-white/10 bg-white/[0.04] px-3 py-2">
                <p className="text-lg font-semibold text-emerald-300">{totals.checkoutCount}</p>
                <p className="text-[10px] uppercase tracking-wider text-white/40">Checkout</p>
              </div>
              <div className="rounded-lg border border-white/10 bg-white/[0.04] px-3 py-2">
                <p className="text-lg font-semibold text-sky-300">{totals.apiCount}</p>
                <p className="text-[10px] uppercase tracking-wider text-white/40">API</p>
              </div>
            </div>
          </div>

          <div className="absolute bottom-32 left-1/2 z-10 hidden -translate-x-1/2 items-center gap-2 rounded-full border border-emerald-300/20 bg-black/30 px-4 py-2 text-xs text-white/60 backdrop-blur-md sm:flex">
            <span className="h-2 w-2 rounded-full bg-emerald-300 shadow-[0_0_16px_rgba(110,231,183,0.9)]" />
            <span className="font-semibold uppercase tracking-[0.22em] text-emerald-300">Ao vivo</span>
            <span>{totals.visitsNow} visitas agora</span>
          </div>
        </div>
      )}

      <style>{`
        .live-globe-icon {
          position: relative;
          width: 1.9rem;
          height: 1.9rem;
          display: block;
        }

        .live-globe-icon__svg {
          position: absolute;
          inset: 0;
          filter: drop-shadow(0 0 8px rgba(74, 222, 128, 0.22));
        }

        .live-globe-icon__land {
          fill: currentColor;
          opacity: 0.18;
          animation: live-globe-land-slide 7.5s linear infinite;
        }

        .live-globe-icon__grid {
          filter: drop-shadow(0 0 1px rgba(34, 197, 94, 0.18));
        }

        .live-globe-icon__meridian {
          transform-box: fill-box;
          transform-origin: center;
          animation: live-globe-meridian-sweep 6.4s ease-in-out infinite;
        }

        .live-globe-icon__meridian--one {
          animation-delay: -4.2s;
        }

        .live-globe-icon__meridian--two {
          animation-delay: -2.1s;
        }

        .live-globe-icon__meridian--three {
          animation-delay: 0s;
        }

        @keyframes live-globe-land-slide {
          from { transform: translateX(-16px); }
          to { transform: translateX(0); }
        }

        @keyframes live-globe-meridian-sweep {
          0% { transform: translateX(-3.5px) scaleX(0.28); opacity: 0.08; }
          16% { transform: translateX(-2px) scaleX(0.62); opacity: 0.28; }
          36% { transform: translateX(0) scaleX(1); opacity: 0.62; }
          58% { transform: translateX(2px) scaleX(0.62); opacity: 0.30; }
          76%, 100% { transform: translateX(3.5px) scaleX(0.28); opacity: 0.08; }
        }
      `}</style>
    </>
  );
}

function MetricCard({ label, value, live = false }: { label: string; value: string; live?: boolean }) {
  return (
    <div className="rounded-card border border-white/10 bg-white/[0.07] px-5 py-4 text-center shadow-2xl backdrop-blur-xl">
      <div className="mb-2 flex items-center justify-center gap-2">
        {live && <BoltIcon className="h-4 w-4 text-emerald-300" />}
        <p className="text-[10px] font-semibold uppercase tracking-[0.18em] text-white/45">{label}</p>
      </div>
      <p className="text-2xl font-semibold text-white">{value}</p>
    </div>
  );
}
