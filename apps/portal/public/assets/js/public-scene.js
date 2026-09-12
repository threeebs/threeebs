import * as THREE from '/assets/vendor/three.module.min.js';

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const compactViewport = window.matchMedia('(max-width: 48rem)');

function createRenderer(canvas) {
  try {
    return new THREE.WebGLRenderer({
      canvas,
      alpha: true,
      antialias: !compactViewport.matches,
      powerPreference: 'low-power'
    });
  } catch {
    return null;
  }
}

function buildHero(scene) {
  const group = new THREE.Group();
  const mint = new THREE.Color(0x34af90);
  const mintSoft = new THREE.Color(0x82f5d9);
  const positions = [
    new THREE.Vector3(-2.15, 1.8, 0),
    new THREE.Vector3(2.15, .6, .25),
    new THREE.Vector3(-2.15, -.6, -.2),
    new THREE.Vector3(2.15, -1.8, .1)
  ];
  const nodes = [];

  positions.forEach((position, index) => {
    const node = new THREE.Mesh(
      new THREE.IcosahedronGeometry(index === 0 ? .23 : .29 + index * .035, 1),
      new THREE.MeshBasicMaterial({
        color: index === positions.length - 1 ? mintSoft : mint,
        transparent: true,
        opacity: .78
      })
    );
    node.position.copy(position);
    node.userData.phase = index * .9;
    nodes.push(node);
    group.add(node);

    const ring = new THREE.Mesh(
      new THREE.RingGeometry(.42 + index * .04, .435 + index * .04, 40),
      new THREE.MeshBasicMaterial({
        color: mint,
        transparent: true,
        opacity: .18,
        side: THREE.DoubleSide
      })
    );
    ring.position.copy(position);
    ring.userData.phase = index * .9;
    group.add(ring);
  });

  const curve = new THREE.CatmullRomCurve3(positions);
  const path = new THREE.Line(
    new THREE.BufferGeometry().setFromPoints(curve.getPoints(90)),
    new THREE.LineBasicMaterial({ color: mintSoft, transparent: true, opacity: .42 })
  );
  group.add(path);

  const progress = new THREE.Mesh(
    new THREE.SphereGeometry(.085, 12, 12),
    new THREE.MeshBasicMaterial({ color: mintSoft, transparent: true, opacity: .95 })
  );
  progress.position.copy(positions[0]);
  group.add(progress);

  const particleCount = compactViewport.matches ? 42 : 90;
  const values = new Float32Array(particleCount * 3);
  for (let i = 0; i < particleCount; i += 1) {
    const point = curve.getPoint(i / Math.max(1, particleCount - 1));
    values[i * 3] = point.x + (Math.random() - .5) * 1.6;
    values[i * 3 + 1] = point.y + (Math.random() - .5) * 1.4;
    values[i * 3 + 2] = point.z + (Math.random() - .5) * 1.8;
  }
  const particlesGeometry = new THREE.BufferGeometry();
  particlesGeometry.setAttribute('position', new THREE.BufferAttribute(values, 3));
  const particles = new THREE.Points(
    particlesGeometry,
    new THREE.PointsMaterial({
      color: mintSoft,
      size: compactViewport.matches ? .045 : .055,
      transparent: true,
      opacity: .45
    })
  );
  group.add(particles);
  scene.add(group);

  return (time, pointer) => {
    group.rotation.y += (pointer.x * .13 - group.rotation.y) * .025;
    group.rotation.x += (-pointer.y * .08 - group.rotation.x) * .025;
    particles.rotation.y = time * .025;
    progress.position.copy(curve.getPoint((time * .075) % 1));
    nodes.forEach((node) => {
      const pulse = 1 + Math.sin(time * 1.25 - node.userData.phase) * .09;
      node.scale.setScalar(pulse);
    });
  };
}

function buildAmbient(scene) {
  const group = new THREE.Group();
  const mint = new THREE.Color(0x34af90);
  const count = compactViewport.matches ? 20 : 34;
  const positions = new Float32Array(count * 3);

  for (let i = 0; i < count; i += 1) {
    const angle = (i / count) * Math.PI * 2;
    const radius = 1.3 + (i % 5) * .23;
    positions[i * 3] = Math.cos(angle) * radius;
    positions[i * 3 + 1] = Math.sin(angle * 1.7) * 1.25;
    positions[i * 3 + 2] = Math.sin(angle) * .8;
  }

  const geometry = new THREE.BufferGeometry();
  geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
  const points = new THREE.Points(
    geometry,
    new THREE.PointsMaterial({ color: mint, size: .065, transparent: true, opacity: .55 })
  );
  group.add(points);

  const ring = new THREE.LineLoop(
    new THREE.BufferGeometry().setFromPoints(
      Array.from({ length: 48 }, (_, index) => {
        const angle = index / 48 * Math.PI * 2;
        return new THREE.Vector3(Math.cos(angle) * 2.15, Math.sin(angle) * 2.15, 0);
      })
    ),
    new THREE.LineBasicMaterial({ color: mint, transparent: true, opacity: .16 })
  );
  group.add(ring);
  scene.add(group);

  return (time, pointer) => {
    group.rotation.z = time * .035;
    group.rotation.y += (pointer.x * .16 - group.rotation.y) * .02;
    group.rotation.x += (-pointer.y * .12 - group.rotation.x) * .02;
  };
}

function mountScene(root) {
  const canvas = root.querySelector('canvas');
  if (!canvas) return;
  const renderer = createRenderer(canvas);
  if (!renderer) return;

  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(42, 1, .1, 100);
  camera.position.z = root.dataset.threeScene === 'hero' ? 7.4 : 6.2;
  const update = root.dataset.threeScene === 'hero' ? buildHero(scene) : buildAmbient(scene);
  const pointer = { x: 0, y: 0 };
  let visible = true;
  let frame = 0;

  renderer.setClearColor(0x000000, 0);
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, compactViewport.matches ? 1.25 : 1.75));

  const resize = () => {
    const width = Math.max(1, root.clientWidth);
    const height = Math.max(1, root.clientHeight);
    camera.aspect = width / height;
    camera.updateProjectionMatrix();
    renderer.setSize(width, height, false);
    renderer.render(scene, camera);
  };

  const renderFrame = (milliseconds = 0) => {
    const time = milliseconds / 1000;
    update(time, pointer);
    renderer.render(scene, camera);
  };

  const animate = (milliseconds) => {
    if (!visible || reducedMotion.matches) {
      frame = 0;
      return;
    }
    renderFrame(milliseconds);
    frame = requestAnimationFrame(animate);
  };

  root.addEventListener('pointermove', (event) => {
    if (reducedMotion.matches) return;
    const bounds = root.getBoundingClientRect();
    pointer.x = ((event.clientX - bounds.left) / bounds.width) * 2 - 1;
    pointer.y = ((event.clientY - bounds.top) / bounds.height) * 2 - 1;
  }, { passive: true });

  root.addEventListener('pointerleave', () => {
    pointer.x = 0;
    pointer.y = 0;
  }, { passive: true });

  const observer = new IntersectionObserver(([entry]) => {
    visible = entry.isIntersecting;
    if (visible && !frame && !reducedMotion.matches) frame = requestAnimationFrame(animate);
  }, { rootMargin: '100px' });
  observer.observe(root);

  const resizeObserver = new ResizeObserver(resize);
  resizeObserver.observe(root);
  resize();
  renderFrame(0);
  root.classList.add('is-webgl');

  if (!reducedMotion.matches) frame = requestAnimationFrame(animate);
}

function initialise() {
  document.querySelectorAll('[data-three-scene]').forEach(mountScene);
}

if ('requestIdleCallback' in window) {
  window.requestIdleCallback(initialise, { timeout: 1200 });
} else {
  window.setTimeout(initialise, 0);
}
